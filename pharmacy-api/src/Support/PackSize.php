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
