<?php

namespace Tests\Feature\Api;

use App\Models\Bookmaker;
use App\Models\User;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\RequestValidator;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The API keeps to its published contract, docs/api/openapi.yaml: every API route is
 * documented, the documented examples are valid and work, and real requests and
 * responses match the schemas. When one of these fails, change the code and the
 * contract together (and the contract's version and changelog).
 */
class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = __DIR__.'/../../../docs/api/openapi.yaml';

    /** Where the contract's `servers` url puts its paths. */
    private const BASE_PATH = '/api/v1';

    public function test_every_api_route_is_in_the_contract(): void
    {
        $documented = [];

        foreach ($this->contract()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.self::BASE_PATH.$path;
            }
        }

        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
            ->flatMap(fn (Route $route): array => array_map(
                fn (string $method): string => $method.' /'.$route->uri(),
                array_diff($route->methods(), ['HEAD']),
            ))
            ->values()
            ->all();

        $this->assertNotEmpty($routes);
        $this->assertEqualsCanonicalizing($documented, $routes, 'Every API route must be in docs/api/openapi.yaml, and nothing else.');
    }

    public function test_the_response_examples_match_their_schemas(): void
    {
        $responses = $this->contract()['components']['responses'];
        $examples = 0;

        foreach ($this->contract()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                foreach ($operation['responses'] as $status => $response) {
                    if (isset($response['$ref'])) {
                        $response = $responses[basename($response['$ref'])];
                    }

                    $content = $response['content']['application/json'] ?? [];
                    $values = isset($content['example']) ? [$content['example']] : array_column($content['examples'] ?? [], 'value');
                    $headers = ['Content-Type' => 'application/json', ...array_map(fn (): string => '1', $response['headers'] ?? [])];

                    foreach ($values as $value) {
                        $this->responseValidator()->validate(
                            new OperationAddress($path, $method),
                            new Response((int) $status, $headers, (string) json_encode($value)),
                        );
                        $examples++;
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(5, $examples);
    }

    public function test_the_request_examples_are_accepted_and_the_responses_match_the_contract(): void
    {
        $examples = $this->contract()['paths']['/bets']['post']['requestBody']['content']['application/json']['examples'];

        $this->assertNotEmpty($examples);

        foreach ($examples as $name => $example) {
            $user = User::factory()->create();
            Bookmaker::factory()->for($user)->create(['name' => 'Unibet']);
            $token = $user->createToken('Contract')->plainTextToken;

            $response = $this->sendToApi($example['value'], $token);

            $response->assertCreated();
            $this->assertSame(1, $user->bets()->count(), "Example [{$name}] was not recorded.");
            $this->assertResponseMatchesContract($response);
        }
    }

    public function test_nulls_integers_and_decimal_commas_are_accepted_and_match_the_contract(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();

        $response = $this->sendToApi([
            'bookmaker_id' => $bookmaker->id,
            'placed_at' => '2026-11-19T18:30',
            'stake' => '0.5',
            'exchange_rate' => 1,
            'is_free_bet' => true,
            'tipster' => null,
            'tags' => [],
            'notes' => null,
            'selections' => [
                ['sport' => 'Tennis', 'teams' => ['Sinner'], 'event_name' => null, 'pick' => 'Sinner', 'odds' => 2, 'closing_odds' => null, 'competition' => null, 'market' => null, 'event_starts_at' => null],
                ['sport' => 'Golf', 'event_name' => 'The Open', 'pick' => 'McIlroy', 'odds' => '12,5', 'closing_odds' => '11.255'],
            ],
        ], $user->createToken('Contract')->plainTextToken);

        $response->assertCreated()->assertJsonPath('data.total_odds', '25.00');
        $this->assertResponseMatchesContract($response);
    }

    /**
     * Requests the contract rejects are rejected by the API too, with a response the
     * contract describes.
     *
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('invalidRequests')]
    public function test_requests_the_contract_rejects_get_a_documented_422(array $change): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $body = array_replace([
            'bookmaker_id' => $bookmaker->id,
            'stake' => '10',
            'selections' => [['sport' => 'Football', 'event_name' => 'Arsenal v Chelsea', 'pick' => 'Arsenal', 'odds' => '1.95']],
        ], $change);

        try {
            $this->requestValidator()->validate($this->psrRequest($body, 'token'));
            $this->fail('The contract accepts a request the API must reject.');
        } catch (ValidationFailed) {
            // The contract rejects it; so must the API.
        }

        $response = $this->sendToApi($body, $user->createToken('Contract')->plainTextToken, isValid: false)->assertUnprocessable();

        $this->assertResponseMatchesContract($response);
        $this->assertSame(0, $user->bets()->count());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidRequests(): array
    {
        return [
            'no bookmaker' => [['bookmaker_id' => null]],
            'bookmaker by name and id' => [['bookmaker' => 'Unibet']],
            'stake as a float' => [['stake' => 10.5]],
            'no selections' => [['selections' => []]],
            '21 selections' => [['selections' => array_fill(0, 21, ['sport' => 'Football', 'event_name' => 'A v B', 'pick' => 'A', 'odds' => '1.1'])]],
            'odds as a float' => [['selections' => [['sport' => 'Football', 'event_name' => 'A v B', 'pick' => 'A', 'odds' => 1.95]]]],
            'no event name or teams' => [['selections' => [['sport' => 'Football', 'pick' => 'A', 'odds' => '1.95']]]],
            'a date without a time' => [['placed_at' => '2026-11-19']],
        ];
    }

    public function test_a_request_without_a_token_gets_the_documented_401(): void
    {
        $response = $this->postJson(route('api.v1.bets.store'), [])->assertUnauthorized();

        $this->assertResponseMatchesContract($response);
    }

    public function test_requests_over_the_rate_limit_get_the_documented_429(): void
    {
        $token = User::factory()->create()->createToken('Contract')->plainTextToken;

        for ($request = 1; $request <= 60; $request++) {
            $this->sendToApi([], $token, isValid: false)->assertUnprocessable();
        }

        $response = $this->sendToApi([], $token, isValid: false)->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertResponseMatchesContract($response);
    }

    /**
     * Send the body to `POST /bets`; a body meant to be valid is first checked against
     * the contract, so the tests only send requests a consumer could send.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function sendToApi(array $body, string $token, bool $isValid = true): TestResponse
    {
        if ($isValid) {
            $this->requestValidator()->validate($this->psrRequest($body, $token));
        }

        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson(route('api.v1.bets.store'), $body);
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
    private function assertResponseMatchesContract(TestResponse $response): void
    {
        $headers = array_filter($response->headers->all(), fn (array $values): bool => $values !== []);

        $this->responseValidator()->validate(
            new OperationAddress('/bets', 'post'),
            new Response($response->getStatusCode(), $headers, (string) $response->getContent()),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function psrRequest(array $body, string $token): Request
    {
        return new Request('POST', 'http://localhost'.self::BASE_PATH.'/bets', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
        ], (string) json_encode($body));
    }

    private function requestValidator(): RequestValidator
    {
        return (new ValidatorBuilder)->fromYamlFile(self::CONTRACT)->getRequestValidator();
    }

    private function responseValidator(): ResponseValidator
    {
        return (new ValidatorBuilder)->fromYamlFile(self::CONTRACT)->getResponseValidator();
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        return Yaml::parseFile(self::CONTRACT);
    }
}
