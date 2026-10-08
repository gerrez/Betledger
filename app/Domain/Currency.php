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
}
