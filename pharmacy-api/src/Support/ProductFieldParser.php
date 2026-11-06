<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Infer / normalise Product Master schedule, margin category, dosage form, and strength.
 */
final class ProductFieldParser
{
    /** @var array<string, string> */
    private static array $scheduleByMolecule = [
        'amphetamine' => 'X', 'dexamphetamine' => 'X', 'methamphetamine' => 'X',
        'methylphenidate' => 'X', 'phenobarbital' => 'X', 'phenobarbitone' => 'X',
        'cefixime' => 'H1', 'cefpodoxime' => 'H1', 'ceftriaxone' => 'H1', 'ceftazidime' => 'H1',
        'cefepime' => 'H1', 'cefoperazone' => 'H1', 'cefdinir' => 'H1', 'cefuroxime' => 'H1',
        'cefotaxime' => 'H1', 'meropenem' => 'H1', 'imipenem' => 'H1', 'moxifloxacin' => 'H1',
        'linezolid' => 'H1', 'vancomycin' => 'H1', 'isoniazid' => 'H1', 'rifampicin' => 'H1',
        'ethambutol' => 'H1', 'pyrazinamide' => 'H1', 'alprazolam' => 'H1', 'lorazepam' => 'H1',
        'diazepam' => 'H1', 'clonazepam' => 'H1', 'tramadol' => 'H1', 'codeine' => 'H1',
        'morphine' => 'H1', 'fentanyl' => 'H1', 'zolpidem' => 'H1', 'ketamine' => 'H1',
        'insulin' => 'G', 'levothyroxine' => 'G', 'glibenclamide' => 'G', 'glimepiride' => 'G',
        'amoxicillin' => 'H', 'azithromycin' => 'H', 'ciprofloxacin' => 'H', 'ofloxacin' => 'H',
        'levofloxacin' => 'H', 'metronidazole' => 'H', 'fluconazole' => 'H', 'atorvastatin' => 'H',
        'amlodipine' => 'H', 'telmisartan' => 'H', 'metformin' => 'H', 'pantoprazole' => 'H',
        'omeprazole' => 'H', 'domperidone' => 'H', 'warfarin' => 'H', 'clopidogrel' => 'H',
        'pregabalin' => 'H', 'gabapentin' => 'H', 'prednisolone' => 'H', 'montelukast' => 'H',
        'paracetamol' => 'OTC', 'acetaminophen' => 'OTC', 'ibuprofen' => 'OTC',
        'cetirizine' => 'OTC', 'loratadine' => 'OTC', 'chlorpheniramine' => 'OTC',
        'calcium carbonate' => 'OTC', 'clotrimazole' => 'OTC',
    ];

    /** @var array<string, int> */
    private static array $scheduleRank = [
        'OTC' => 0, 'H' => 1, 'G' => 1, 'H1' => 2, 'X' => 3, 'RESTRICTED' => 4,
    ];

    /** @var list<string> */
    private static array $dosageForms = [
        'TABLET', 'CAPSULE', 'SYRUP', 'INJECTION', 'CREAM', 'OINTMENT', 'DROPS',
        'POWDER', 'SUSPENSION', 'GEL', 'LOTION', 'INHALER', 'SACHET', 'SPRAY',
        'SOLUTION', 'INFUSION', 'PATCH', 'OTHER',
    ];

    public static function inferSchedule(string $productName, ?string $genericName, ?string $composition, ?string $genericSchedule): string
    {
        $text = self::normKey(trim($productName . ' ' . (string) $genericName . ' ' . (string) $composition));
        $best = null;
        $bestLen = 0;
        foreach (self::$scheduleByMolecule as $mol => $sch) {
            if ($mol !== '' && str_contains($text, $mol) && strlen($mol) > $bestLen) {
                $bestLen = strlen($mol);
                $best = $sch;
            }
        }
        if ($best !== null) {
            return $best;
        }
        if ($genericSchedule !== null && $genericSchedule !== '' && $genericSchedule !== 'OTC') {
            return $genericSchedule;
        }
        if (preg_match('/\d+\s*(mg|mcg|ml)\b/i', $productName)) {
            return 'H';
        }
        return 'OTC';
    }

    public static function scheduleShouldUpgrade(string $current, string $inferred): bool
    {
        $c = self::$scheduleRank[$current] ?? 0;
        $i = self::$scheduleRank[$inferred] ?? 0;
        return $i > $c;
    }

    public static function isLikelyOtcGeneral(string $name, string $productType): bool
    {
        $n = strtoupper($name);
        $t = strtoupper(trim($productType));
        if (in_array($t, ['COSMETICS', 'COSMETIC', 'GENERAL', 'FOOD/NUTRA', 'PATANJALI'], true)) {
            return true;
        }
        return (bool) preg_match(
            '/\b(BALM|VAPORUB|VAPOUR|HONEY|COTTON|SYRINGE|BANDAGE|GAUZE|SHAMPOO|SOAP|TOOTHPASTE|SUNSCREEN|FACEWASH|FACE WASH|LOTION|MOISTUR|DIAPER|SANITIZER|CONDOM|SURGICAL|NEEDLE|GLOVE|MASK)\b/',
            $n
        );
    }

    public static function normalizeProductCategory(string $productType, ?string $current): string
    {
        $cur = trim((string) $current);
        $t = trim($productType);
        if (preg_match('/^COSMETIC/i', $t)) {
            return 'Cosmetic';
        }
        if ($t === 'Patanjali') {
            return 'Ayurvedic';
        }
        if ($t === 'Food/Nutra') {
            return 'NUTRA';
        }
        if ($t === 'General') {
            if (preg_match('/SURGICAL|COTTON|SYRINGE|BANDAGE|GAUZE|NEEDLE|GLOVE/i', $cur . ' ' . $t)) {
                return 'Surgical';
            }
            if (preg_match('/COSMET|SHAMPOO|SOAP|CREAM|LOTION/i', $cur)) {
                return 'Cosmetic';
            }
        }
        if (preg_match('/^AYURVEDIC/i', $cur) || preg_match('/AYURVED|PATANJALI|HERBAL/i', $cur)) {
            return 'Ayurvedic';
        }
        if (preg_match('/^COSMETIC/i', $cur)) {
            return 'Cosmetic';
        }
        if (preg_match('/^SURGICAL/i', $cur)) {
            return 'Surgical';
        }
        if (preg_match('/^(FOOD|NUTRA)/i', $cur)) {
            return str_starts_with(strtoupper($cur), 'FOOD') ? 'FOOD' : 'NUTRA';
        }
        if (preg_match('/^ALLOPATHIC|^GENERIC|^PCD|^ETHICAL/i', $cur) || $cur === '') {
            return 'Allopathy';
        }
        return $cur;
    }

    public static function parseDosageForm(string $name, ?string $packingDesc, ?string $packSize): ?string
    {
        $blob = strtoupper($name . ' ' . (string) $packingDesc . ' ' . (string) $packSize);
        // Pack-only hints (Retail packingDesc / pack_size)
        if (preg_match('/\b(STRIP OF|X)\s*\d+\s*(TABLETS?|TABS?)\b/', $blob) || preg_match('/\d+\s*(TABLETS?|TABS?)\b/', (string) $packSize)) {
            return 'TABLET';
        }
        if (preg_match('/\b(STRIP OF|X)\s*\d+\s*(CAPSULES?|CAPS?)\b/', $blob) || preg_match('/\d+\s*(CAPSULES?|CAPS?)\b/', (string) $packSize)) {
            return 'CAPSULE';
        }
        if (preg_match('/\b(BOTTLE OF|)\s*\d+\s*ML\b.*\b(SYRUP|SUSP|SOLUTION)\b/', $blob) || preg_match('/\b(SYRUP|SUSPENSION)\b/', (string) $packSize)) {
            return 'SYRUP';
        }
        $map = [
            'TABLET' => '/\b(TABLETS?|TAB\.?|TABS)\b/',
            'CAPSULE' => '/\b(CAPSULES?|CAP\.?|CAPS|SOFTGEL|SOFT GEL)\b/',
            'SYRUP' => '/\b(SYRUP|SYP\.?|SYR\.?)\b/',
            'SUSPENSION' => '/\b(SUSPENSION|SUSP\.?)\b/',
            'INJECTION' => '/\b(INJECTION|INJ\.?|VIAL|AMPOULE|AMPOUL|INFUSION)\b/',
            'INFUSION' => '/\b(INFUSION|IV FLUID|DNS|RL|NS BAG)\b/',
            'DROPS' => '/\b(DROPS?|EYE DROP|EYE DROPS|EARDROP|NASAL DROP)\b/',
            'CREAM' => '/\b(CREAM|CR\.?)\b/',
            'OINTMENT' => '/\b(OINTMENT|OINT\.?)\b/',
            'GEL' => '/\b(GEL)\b/',
            'LOTION' => '/\b(LOTION)\b/',
            'POWDER' => '/\b(POWDER|POWD\.?|DUSTING)\b/',
            'INHALER' => '/\b(INHALER|INHALATION|ROTACAP|ROTACAPS|RESPULE|NEBUL)\b/',
            'SACHET' => '/\b(SACHET|SAH\.?)\b/',
            'SPRAY' => '/\b(SPRAY|MOUTH SPRAY|NASAL SPRAY)\b/',
            'SOLUTION' => '/\b(SOLUTION|SOL\.?)\b/',
            'PATCH' => '/\b(PATCH|TRANSDERMAL)\b/',
        ];
        foreach ($map as $form => $rx) {
            if (preg_match($rx, $blob)) {
                return $form;
            }
        }
        $pack = strtoupper(trim((string) $packSize));
        if ($pack !== '' && preg_match('/^\d+\s*[xX×]\s*\d+/', $pack) && preg_match('/\b(TAB|CAP|TABLET|CAPSULE|MG|MCG)\b/', $blob)) {
            return str_contains($blob, 'CAP') ? 'CAPSULE' : 'TABLET';
        }
        if ($pack !== '' && preg_match("/\d+\s*['\x27]S\b/", $pack) && preg_match('/\b(TAB|TABLET|CAP|CAPSULE)\b/', $blob)) {
            return str_contains($blob, 'CAP') ? 'CAPSULE' : 'TABLET';
        }
        return null;
    }

    public static function parseStrength(string $name, ?string $composition, ?string $currentStrength): ?string
    {
        $cur = strtoupper(trim((string) $currentStrength));
        $trustCurrent = $cur !== ''
            && !preg_match('/^(TAB|TABLET|CAP|CAPSULE|SYP|SYRUP|INJ|CREAM|GEL|DROP)/', $cur)
            && !self::isSuspiciousStrength($cur, $name);
        if ($trustCurrent) {
            return self::formatStrength($cur);
        }
        $sources = [$name, (string) $composition];
        foreach ($sources as $src) {
            if ($src === '') {
                continue;
            }
            $patterns = [
                '/(\d+(?:\.\d+)?)\s*(MG|MCG|ML|IU|GM|%)\b/i',
                '/(\d+(?:\.\d+)?)(MG|MCG|ML|IU|GM|%)\b/i',
            ];
            foreach ($patterns as $rx) {
                if (!preg_match_all($rx, $src, $m, PREG_SET_ORDER)) {
                    continue;
                }
                $parts = [];
                foreach ($m as $hit) {
                    $u = strtoupper($hit[2]);
                    if ($u === 'GM') {
                        $u = 'G';
                    }
                    $parts[] = rtrim(rtrim($hit[1], '0'), '.') . ' ' . $u;
                }
                if ($parts) {
                    return self::formatStrength(implode(' + ', array_slice($parts, 0, 4)));
                }
            }
            // Brand strength shorthand: CEFIX 200 TAB, DOLO 650, etc.
            if (preg_match('/\b(\d{2,4})\s*(?:MG|MCG|ML|IU|GM|%)?\s*(?:TAB|TABLET|CAP|CAPSULE|DT|SR|XR|CR|MR|OD|BD)\b/i', $src, $m)) {
                return self::formatStrength($m[1] . ' MG');
            }
        }
        return null;
    }

    public static function fixSwappedDosageStrength(?string $dosage, ?string $strength): array
    {
        $d = trim((string) $dosage);
        $s = trim((string) $strength);
        $dUpper = strtoupper($d);
        $sUpper = strtoupper($s);
        $isForm = static fn (string $v): bool => (bool) preg_match(
            '/^(TABLET|CAPSULE|SYRUP|INJECTION|CREAM|OINTMENT|DROPS|POWDER|SUSPENSION|GEL|LOTION|INHALER|SACHET|SPRAY|SOLUTION|INFUSION|PATCH|OTHER|TAB|CAP|SYP|INJ|DROP)/',
            $v
        );
        $isStrength = static fn (string $v): bool => (bool) preg_match('/^\d/', $v) || preg_match('/\d+\s*(MG|MCG|ML|G|IU|%)/i', $v);

        if ($d !== '' && $s !== '' && $isStrength($dUpper) && $isForm($sUpper)) {
            return [$sUpper, self::formatStrength($dUpper)];
        }
        if ($d !== '' && $isStrength($dUpper) && ($s === '' || $isForm($sUpper))) {
            $form = $isForm($sUpper) ? $sUpper : null;
            return [$form ?? $d, self::formatStrength($dUpper)];
        }
        return [$d !== '' ? strtoupper($d) : null, $s !== '' ? self::formatStrength($sUpper) : null];
    }

    private static function isSuspiciousStrength(string $strength, string $name): bool
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(MG|MCG|ML|G|IU|%)$/i', $strength, $m)) {
            return false;
        }
        $val = (float) $m[1];
        if ($val > 20 || $m[2] !== 'MG') {
            return false;
        }
        if (preg_match('/\b(\d{2,4})\s*(?:MG|MCG|ML|IU|GM|%)?\s*(?:TAB|TABLET|CAP|CAPSULE|DT|SR|XR|CR|MR|OD|BD)\b/i', $name, $hit)) {
            return (float) $hit[1] > $val + 10;
        }
        return false;
    }

    private static function formatStrength(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        $s = preg_replace('/\bGM\b/', 'G', $s) ?? $s;
        return mb_substr($s, 0, 80);
    }

    private static function normKey(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9+.\-\/\s]/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
}
