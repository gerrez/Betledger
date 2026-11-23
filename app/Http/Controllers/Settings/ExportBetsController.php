<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Export\BetsCsv;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download all of the user's bets as a CSV file ("Export" in docs/spec.md). It is the
 * only backup a user has, so it is also linked from the delete-account dialog.
 */
class ExportBetsController extends Controller
{
    /** Bets loaded (with their relations) per query while writing the file. */
    private const CHUNK_SIZE = 200;

    /** Makes Excel on Windows read the file as UTF-8. */
    private const BYTE_ORDER_MARK = "\u{FEFF}";

    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $csv = new BetsCsv($user->base_currency);

        /*
         * lazy() pages by offset and keeps this order; lazyById() would order by id only.
         * The order is unique (id breaks ties), so no bet is skipped or repeated.
         */
        $bets = $user->bets()
            ->with([
                'bookmaker',
                'tipster',
                'tags',
                'selections.sport',
                'selections.competition',
                'selections.market',
                'selections.teams',
            ])
            ->orderBy('placed_at')
            ->orderBy('id');

        return response()->streamDownload(function () use ($bets, $csv): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new RuntimeException('Could not open the output stream.');
            }

            fwrite($output, self::BYTE_ORDER_MARK);
            $this->writeRow($output, BetsCsv::header());

            foreach ($bets->lazy(self::CHUNK_SIZE) as $bet) {
                foreach ($csv->rows($bet) as $row) {
                    $this->writeRow($output, $row);
                }
            }

            fclose($output);
        }, 'betledger-bets-'.now()->utc()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Write one RFC 4180 row: comma separated, fields with a comma, quote or line break
     * quoted, quotes doubled, no backslash escaping, CRLF at the end.
     *
     * @param  resource  $output
     * @param  list<string>  $row
     */
    private function writeRow($output, array $row): void
    {
        fputcsv($output, $row, ',', '"', '', "\r\n");
    }
}
