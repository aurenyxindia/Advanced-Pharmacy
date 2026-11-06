<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pack-size normaliser.
 *
 * Distributors write the same pack many ways:
 *   strip : 1x10, 1*10, 1&10, 1X10, 10x1, 10s, 10's, 10 tab, 10 cap, 10nos, blister of 10
 *   liquid: 100ml, 1x100ml, 100 ml, 1x1, 1 bottle, 60ml susp
 *   tube  : 15g, 15gm, 1x15g, tube of 15g
 *   inj   : 1 vial, 1x1, 1x5, 5x2ml, 1 amp
 *
 * parse() detects the saleable units-per-pack and a normalised label so loose
 * sales and stock stay correct regardless of the distributor's notation.
 */
final class PackSize
{
    /** Curated standard pack sizes for manual entry dropdowns. */
    public static function standard(): array
    {
        $strips = [4, 5, 6, 8, 10, 12, 14, 15, 16, 20, 28, 30];
        $multi  = [[2, 10], [2, 15], [3, 10], [5, 10], [10, 10], [10, 15]];
        $vols   = [5, 10, 15, 30, 50, 60, 100, 150, 200, 250, 450];
        $tubes  = [5, 10, 15, 20, 30, 50, 100];

        $out = [];
        foreach ($strips as $b) {
            $out[] = ['pack' => '1x' . $b, 'units' => $b, 'form' => 'Strip'];
        }
        foreach ($multi as [$a, $b]) {
            $out[] = ['pack' => $a . 'x' . $b, 'units' => $b, 'form' => 'Strip (multi)'];
        }
        foreach ($vols as $v) {
            $out[] = ['pack' => $v . 'ml', 'units' => 1, 'form' => 'Bottle/Liquid'];
        }
        foreach ($tubes as $g) {
            $out[] = ['pack' => $g . 'g', 'units' => 1, 'form' => 'Tube/Cream'];
        }
        $out[] = ['pack' => '1 vial', 'units' => 1, 'form' => 'Injection'];
        $out[] = ['pack' => '1x5', 'units' => 5, 'form' => 'Ampoule/Vial pack'];
        $out[] = ['pack' => '1 sachet', 'units' => 1, 'form' => 'Sachet'];
        $out[] = ['pack' => '1 unit', 'units' => 1, 'form' => 'Unit'];
        return $out;
    }

    /**
     * Retail master pack_size list (from legacy pack_size table).
     * Labels match Retail dropdown exactly, e.g. "1 x 10", "60 ML".
     *
     * @return list<array{pack:string,label:string,units:int,form:string}>
     */
    public static function retailMaster(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $labels = [
            '1 x 1',
            '1 x 2',
            '1 x 3',
            '1 x 4',
            '1 x 5',
            '1 x 6',
            '1 x 7',
            '1 x 8',
            '1 x 10',
            '1 x 12',
            '1 x 14',
            '1 x 15',
            '1 x 16',
            '1 x 20',
            '1 x 21',
            '1 x 25',
            '1 x 30',
            '1 x 45',
            '1 x 50',
            '1 x 60',
            '1 x 100',
            '1 x 1000',
            '3 x 10',
            '1 GM',
            '1GM',
            '2.5 GM',
            '3 GM',
            '4 GM',
            '4.5 GM',
            '5 GM',
            '10 GM',
            '12 GM',
            '14 GM',
            '15 GM',
            '20 GM',
            '20GM',
            '25GM',
            '30 GM',
            '40 GM',
            '50GM',
            '60 GM',
            '75 GM',
            '80 GM',
            '100 GM',
            '125 GM',
            '200GM',
            '250 GM',
            '300 GM',
            '400 GM',
            '450 GM',
            '500GM',
            '750 GM',
            '0.7 ML',
            '1 ML',
            '2 ML',
            '2.5 ML',
            '3 ML',
            '4 ML',
            '5 ML',
            '6 ML',
            '10 ML',
            '15 ML',
            '20 ML',
            '25 ML',
            '30 ML',
            '35 ML',
            '40 ML',
            '50 ML',
            '60 ML',
            '90 ML',
            '99 ML',
            '100 ML',
            '110 ML',
            '120 ML',
            '150 ML',
            '170 ML',
            '200 ML',
            '300 ML',
            '500 ML',
            '650 ML',
            '600 ML',
            '250 ML',
            '400 ML',
            '450 ML',
            '1 KG',
            '1.2 KG',
            '1.5 KG',
            '2 KG',
            '5 KG',
            '10 KG',
            '15 KG',
            '20 KG',
            '25 KG',
            '80 MG',
            '250 MG',
            '500 MG',
            '1 LIT',
            '2.5 LIT',
            'STRIP ',
            '1 VIAL',
            '2 VIAL',
            '1 AMP',
            '10 Tab',
            '1 BOX',
            '1 x 56',
            '1 x 40',
            '1 x 28',
        ];

        $out = [];
        $seen = [];
        foreach ($labels as $label) {
            $key = strtolower(trim($label));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $parsed = self::parse($label);
            $out[] = [
                'pack'  => $label,
                'label' => $label,
                'units' => (int) $parsed['units_per_pack'],
                'form'  => (string) $parsed['form'],
            ];
        }

        $cache = $out;
        return $out;
    }

    /**
     * @return array{units_per_pack:int, pack_size:string, form:string, raw:string}
     */
    public static function parse(string $raw): array
    {
        $orig = trim($raw);
        if ($orig === '') {
            return ['units_per_pack' => 1, 'pack_size' => '1 unit', 'form' => 'Unit', 'raw' => $orig];
        }

        // Unify separators (x, *, &, ×, space-x-space, -) to 'x' and drop spaces.
        $s = strtolower($orig);
        $s = preg_replace('/\s*[x*×&]\s*/u', 'x', $s);
        $s = preg_replace('/\s+/', '', $s);

        // ---- Volume / weight (liquids, drops, creams, tubes) ----
        if (preg_match('/(\d+(?:\.\d+)?)(ml|l|gm|g|mg|mcg)\b/', $s, $m)) {
            $count = 1;
            if (preg_match('/^(\d+)x/', $s, $c)) {
                $count = max(1, (int) $c[1]);
            }
            $vol = $m[1] . ($m[2] === 'gm' ? 'g' : $m[2]);
            return [
                'units_per_pack' => $count,
                'pack_size'      => $count > 1 ? $count . 'x' . $vol : $vol,
                'form'           => 'Liquid/Volume',
                'raw'            => $orig,
            ];
        }

        // ---- A x B (strips of tablets/capsules) ----
        if (preg_match('/^(\d+)x(\d+)/', $s, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $units = $b > 1 ? $b : ($a > 1 ? $a : 1); // per-strip count
            return [
                'units_per_pack' => max(1, $units),
                'pack_size'      => $a . 'x' . $b,
                'form'           => 'Strip/Pack',
                'raw'            => $orig,
            ];
        }

        // ---- Number + tablet/capsule keyword (10s, 10tab, 10cap, 10nos) ----
        if (preg_match('/^(\d+)(?:\'?s|tabs?|tablets?|caps?|capsules?|nos?|pcs?|pc|units?|sachets?)/', $s, $m)) {
            $n = max(1, (int) $m[1]);
            return ['units_per_pack' => $n, 'pack_size' => '1x' . $n, 'form' => 'Strip/Pack', 'raw' => $orig];
        }

        // ---- Single-unit containers ----
        if (preg_match('/(bottle|btl|vial|amp|ampoule|tube|inj|injection|drops?|respule|sachet|pessary|suppository)/', $s)) {
            $count = 1;
            if (preg_match('/^(\d+)x?/', $s, $c)) {
                $count = max(1, (int) $c[1]);
            }
            return ['units_per_pack' => $count, 'pack_size' => $orig, 'form' => 'Unit', 'raw' => $orig];
        }

        // ---- Bare number ----
        if (preg_match('/^(\d+)$/', $s, $m)) {
            $n = max(1, (int) $m[1]);
            return ['units_per_pack' => $n, 'pack_size' => '1x' . $n, 'form' => 'Strip/Pack', 'raw' => $orig];
        }

        // ---- "strip/blister of N" style ----
        if (preg_match('/(strip|blister)/', $s) && preg_match('/(\d+)/', $s, $m)) {
            $n = max(1, (int) $m[1]);
            return ['units_per_pack' => $n, 'pack_size' => '1x' . $n, 'form' => 'Strip/Pack', 'raw' => $orig];
        }

        return ['units_per_pack' => 1, 'pack_size' => $orig, 'form' => 'Unit', 'raw' => $orig];
    }
}
