<?php

namespace App\Domain\Import\Sportmarket;

/**
 * Reads the CSV of a Sportmarket orders export (Orders → CSV) into rows. Columns are
 * found by their header names, not their position, and columns the import doesn't use
 * are ignored.
 */
final class OrdersFile
{
    /**
     * The header of each column the import uses, by OrderRow property.
     */
    private const COLUMNS = [
        'orderId' => 'Order Id',
        'orderPlaced' => 'Order Placed',
        'competition' => 'Competition',
        'gameDate' => 'Game Date',
        'sport' => 'Sport',
        'home' => 'Home',
        'away' => 'Away',
        'betType' => 'Bet Type',
        'betStatus' => 'Bet Status',
        'bookie' => 'Bookie',
        'currency' => 'User Currency',
        'stake' => 'Stake (User Currency)',
        'price' => 'Price',
        'profitAndLoss' => 'Reconciled P&L (User Currency)',
        'inRunning' => 'In Running',
        'market' => 'Market',
    ];

    private const BYTE_ORDER_MARK = "\u{FEFF}";

    /**
     * @return list<OrderRow>
     *
     * @throws InvalidOrdersFile when the file is empty, lacks a column the import needs, or has a malformed row
     */
    public static function parse(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new InvalidOrdersFile('The file could not be read.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        try {
            return self::readRows($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @return list<OrderRow>
     */
    private static function readRows($stream): array
    {
        $header = self::readRecord($stream);

        if ($header === null) {
            throw new InvalidOrdersFile('The file is empty.');
        }

        $header[0] = str_starts_with($header[0], self::BYTE_ORDER_MARK) ? substr($header[0], strlen(self::BYTE_ORDER_MARK)) : $header[0];
        $positions = self::columnPositions(array_map(trim(...), $header));

        $rows = [];
        $line = 1;

        while (($record = self::readRecord($stream)) !== null) {
            $line++;

            if ($record === [null]) {
                continue;
            }

            if (count($record) !== count($header)) {
                throw new InvalidOrdersFile("Line {$line} has ".count($record).' columns, but the header has '.count($header).'.');
            }

            $values = array_map(fn (int $position): string => trim((string) $record[$position]), $positions);

            if ($values['orderId'] === '') {
                throw new InvalidOrdersFile("Line {$line} has no Order Id.");
            }

            $rows[] = new OrderRow($line, ...$values);
        }

        if ($rows === []) {
            throw new InvalidOrdersFile('The file has no orders.');
        }

        return $rows;
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int> the position of each column the import uses, by OrderRow property
     */
    private static function columnPositions(array $header): array
    {
        $positions = [];
        $missing = [];

        foreach (self::COLUMNS as $property => $name) {
            $position = array_search($name, $header, true);

            if ($position === false) {
                $missing[] = $name;
            } else {
                $positions[$property] = $position;
            }
        }

        if ($missing !== []) {
            throw new InvalidOrdersFile('This is not a Sportmarket orders export: it has no '.implode(', ', $missing).' column'.(count($missing) > 1 ? 's' : '').'.');
        }

        return $positions;
    }

    /**
     * The next CSV record, [null] for a blank line, or null at the end of the file.
     *
     * @param  resource  $stream
     * @return list<string|null>|null
     */
    private static function readRecord($stream): ?array
    {
        $record = fgetcsv($stream, null, ',', '"', '');

        return $record === false ? null : $record;
    }
}
