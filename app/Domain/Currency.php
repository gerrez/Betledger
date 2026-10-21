<?php

namespace App\Domain;

/**
 * The currencies v1 supports: ISO 4217 codes with two decimal places (see Money).
 */
enum Currency: string
{
    case Aud = 'AUD';
    case Cad = 'CAD';
    case Chf = 'CHF';
    case Czk = 'CZK';
    case Dkk = 'DKK';
    case Eur = 'EUR';
    case Gbp = 'GBP';
    case Nok = 'NOK';
    case Nzd = 'NZD';
    case Pln = 'PLN';
    case Sek = 'SEK';
    case Usd = 'USD';

    public function label(): string
    {
        return $this->value.' — '.match ($this) {
            self::Aud => 'Australian dollar',
            self::Cad => 'Canadian dollar',
            self::Chf => 'Swiss franc',
            self::Czk => 'Czech koruna',
            self::Dkk => 'Danish krone',
            self::Eur => 'Euro',
            self::Gbp => 'British pound',
            self::Nok => 'Norwegian krone',
            self::Nzd => 'New Zealand dollar',
            self::Pln => 'Polish złoty',
            self::Sek => 'Swedish krona',
            self::Usd => 'US dollar',
        };
    }

    /**
     * Put the currency symbol on a formatted amount: "€1,492.00", "1,492.00 kr",
     * "CHF 1,492.00". DKK, NOK and SEK all show as "kr".
     */
    public function withSymbol(string $amount): string
    {
        return match ($this) {
            self::Aud => 'A$'.$amount,
            self::Cad => 'C$'.$amount,
            self::Chf => 'CHF '.$amount,
            self::Czk => $amount.' Kč',
            self::Dkk, self::Nok, self::Sek => $amount.' kr',
            self::Eur => '€'.$amount,
            self::Gbp => '£'.$amount,
            self::Nzd => 'NZ$'.$amount,
            self::Pln => $amount.' zł',
            self::Usd => '$'.$amount,
        };
    }

    /**
     * The currency symbol on its own: "€", "kr", "CHF".
     */
    public function symbol(): string
    {
        return trim($this->withSymbol(''));
    }
}
