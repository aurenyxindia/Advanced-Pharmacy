<?php

/**
 * Product Master seeder for the Advanced Pharmacy Suite.
 *
 * Builds a large, realistic catalogue in `pharma_master`:
 *   1. Generic drugs (molecule + strength) with therapeutic class.
 *   2. Branded products from leading Indian manufacturers, with packing, MRP,
 *      schedule classification, product category, GST and auto PTR/PTS.
 *
 * NOTE: molecules, strengths, manufacturers, schedules and MRP ranges are
 * realistic; brand-name combinations beyond well-known ones are generated for
 * volume/testing. Run: php database/seed_products.php [targetCount]
 */

$target = isset($argv[1]) ? max(1000, (int) $argv[1]) : 50000;

$host = '127.0.0.1';
$db   = 'pharma_master';
$user = 'root';
$pass = '';

$pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$now = date('Y-m-d H:i:s');

// ----------------------------------------------------------------------------
// Reference data
// ----------------------------------------------------------------------------

// [molecule, therapeutic_class, schedule, product_category, gst, strengths[], forms[]]
$molecules = [
    // --- Analgesics / Antipyretics (mostly OTC / H) ---
    ['Paracetamol', 'Analgesic/Antipyretic', 'OTC', 'Allopathy', 12, ['250mg','500mg','650mg'], ['TAB','SYP','SUSP']],
    ['Ibuprofen', 'NSAID', 'OTC', 'Allopathy', 12, ['200mg','400mg','600mg'], ['TAB','SUSP']],
    ['Aceclofenac', 'NSAID', 'H', 'Allopathy', 12, ['100mg'], ['TAB']],
    ['Diclofenac', 'NSAID', 'H', 'Allopathy', 12, ['50mg','75mg'], ['TAB','INJ','OINT']],
    ['Nimesulide', 'NSAID', 'H', 'Allopathy', 12, ['100mg'], ['TAB','SUSP']],
    ['Aspirin', 'Antiplatelet/NSAID', 'H', 'Allopathy', 12, ['75mg','150mg','325mg'], ['TAB']],
    ['Naproxen', 'NSAID', 'H', 'Allopathy', 12, ['250mg','500mg'], ['TAB']],
    ['Tramadol', 'Opioid Analgesic', 'H1', 'Allopathy', 12, ['50mg','100mg'], ['TAB','INJ','CAP']],
    ['Etoricoxib', 'NSAID', 'H', 'Allopathy', 12, ['60mg','90mg','120mg'], ['TAB']],

    // --- Antibiotics (H1) ---
    ['Amoxicillin', 'Antibiotic-Penicillin', 'H1', 'Allopathy', 12, ['250mg','500mg'], ['CAP','SYP','TAB']],
    ['Amoxicillin + Clavulanic Acid', 'Antibiotic-Penicillin', 'H1', 'Allopathy', 12, ['375mg','625mg','1g'], ['TAB','SYP','INJ']],
    ['Azithromycin', 'Antibiotic-Macrolide', 'H1', 'Allopathy', 12, ['100mg','250mg','500mg'], ['TAB','SYP']],
    ['Cefixime', 'Antibiotic-Cephalosporin', 'H1', 'Allopathy', 12, ['100mg','200mg'], ['TAB','SYP']],
    ['Cefpodoxime', 'Antibiotic-Cephalosporin', 'H1', 'Allopathy', 12, ['100mg','200mg'], ['TAB','SYP']],
    ['Ceftriaxone', 'Antibiotic-Cephalosporin', 'H1', 'Allopathy', 12, ['250mg','500mg','1g'], ['INJ']],
    ['Cefuroxime', 'Antibiotic-Cephalosporin', 'H1', 'Allopathy', 12, ['250mg','500mg'], ['TAB']],
    ['Cephalexin', 'Antibiotic-Cephalosporin', 'H1', 'Allopathy', 12, ['250mg','500mg'], ['CAP','SYP']],
    ['Ciprofloxacin', 'Antibiotic-Fluoroquinolone', 'H1', 'Allopathy', 12, ['250mg','500mg'], ['TAB','INJ']],
    ['Levofloxacin', 'Antibiotic-Fluoroquinolone', 'H1', 'Allopathy', 12, ['250mg','500mg','750mg'], ['TAB','INJ']],
    ['Ofloxacin', 'Antibiotic-Fluoroquinolone', 'H1', 'Allopathy', 12, ['200mg','400mg'], ['TAB','SYP']],
    ['Doxycycline', 'Antibiotic-Tetracycline', 'H1', 'Allopathy', 12, ['100mg'], ['CAP','TAB']],
    ['Clarithromycin', 'Antibiotic-Macrolide', 'H1', 'Allopathy', 12, ['250mg','500mg'], ['TAB']],
    ['Metronidazole', 'Antibiotic-Nitroimidazole', 'H1', 'Allopathy', 12, ['200mg','400mg'], ['TAB','SYP','INJ']],
    ['Ornidazole', 'Antibiotic-Nitroimidazole', 'H1', 'Allopathy', 12, ['500mg'], ['TAB']],
    ['Linezolid', 'Antibiotic-Oxazolidinone', 'H1', 'Allopathy', 12, ['600mg'], ['TAB','INJ']],
    ['Nitrofurantoin', 'Antibiotic-Urinary', 'H1', 'Allopathy', 12, ['50mg','100mg'], ['TAB','CAP']],
    ['Norfloxacin', 'Antibiotic-Fluoroquinolone', 'H1', 'Allopathy', 12, ['400mg'], ['TAB']],
    ['Faropenem', 'Antibiotic-Penem', 'H1', 'Allopathy', 12, ['200mg','300mg'], ['TAB']],
    ['Clindamycin', 'Antibiotic-Lincosamide', 'H1', 'Allopathy', 12, ['150mg','300mg'], ['CAP']],

    // --- Antifungal / Antiviral / Antiprotozoal ---
    ['Fluconazole', 'Antifungal', 'H', 'Allopathy', 12, ['150mg','200mg'], ['TAB']],
    ['Itraconazole', 'Antifungal', 'H', 'Allopathy', 12, ['100mg','200mg'], ['CAP']],
    ['Acyclovir', 'Antiviral', 'H', 'Allopathy', 12, ['200mg','400mg','800mg'], ['TAB','OINT']],
    ['Albendazole', 'Anthelmintic', 'OTC', 'Allopathy', 12, ['400mg'], ['TAB','SUSP']],

    // --- Anti-allergic / Respiratory ---
    ['Cetirizine', 'Antihistamine', 'OTC', 'Allopathy', 12, ['5mg','10mg'], ['TAB','SYP']],
    ['Levocetirizine', 'Antihistamine', 'H', 'Allopathy', 12, ['5mg'], ['TAB','SYP']],
    ['Fexofenadine', 'Antihistamine', 'H', 'Allopathy', 12, ['120mg','180mg'], ['TAB']],
    ['Montelukast', 'Anti-asthmatic', 'H', 'Allopathy', 12, ['4mg','5mg','10mg'], ['TAB']],
    ['Salbutamol', 'Bronchodilator', 'H', 'Allopathy', 12, ['2mg','4mg'], ['TAB','SYP']],
    ['Levosalbutamol', 'Bronchodilator', 'H', 'Allopathy', 12, ['1mg','2mg'], ['TAB','SYP']],
    ['Ambroxol', 'Mucolytic', 'OTC', 'Allopathy', 12, ['30mg'], ['SYP','TAB']],
    ['Theophylline', 'Bronchodilator', 'H', 'Allopathy', 12, ['100mg','200mg'], ['TAB']],

    // --- GI ---
    ['Pantoprazole', 'Proton Pump Inhibitor', 'H', 'Allopathy', 12, ['20mg','40mg'], ['TAB','INJ']],
    ['Omeprazole', 'Proton Pump Inhibitor', 'H', 'Allopathy', 12, ['20mg','40mg'], ['CAP']],
    ['Rabeprazole', 'Proton Pump Inhibitor', 'H', 'Allopathy', 12, ['20mg'], ['TAB']],
    ['Esomeprazole', 'Proton Pump Inhibitor', 'H', 'Allopathy', 12, ['20mg','40mg'], ['TAB']],
    ['Domperidone', 'Prokinetic', 'H', 'Allopathy', 12, ['10mg'], ['TAB','SYP']],
    ['Ondansetron', 'Antiemetic', 'H', 'Allopathy', 12, ['4mg','8mg'], ['TAB','INJ','SYP']],
    ['Ranitidine', 'H2 Blocker', 'H', 'Allopathy', 12, ['150mg','300mg'], ['TAB']],
    ['Dicyclomine', 'Antispasmodic', 'H', 'Allopathy', 12, ['10mg','20mg'], ['TAB','INJ']],
    ['Loperamide', 'Antidiarrheal', 'OTC', 'Allopathy', 12, ['2mg'], ['TAB']],
    ['ORS', 'Rehydration', 'OTC', 'Allopathy', 5, ['Sachet'], ['SYP']],

    // --- Cardiac / Antihypertensive / Lipid ---
    ['Amlodipine', 'Calcium Channel Blocker', 'H', 'Allopathy', 12, ['2.5mg','5mg','10mg'], ['TAB']],
    ['Telmisartan', 'ARB', 'H', 'Allopathy', 12, ['20mg','40mg','80mg'], ['TAB']],
    ['Losartan', 'ARB', 'H', 'Allopathy', 12, ['25mg','50mg'], ['TAB']],
    ['Olmesartan', 'ARB', 'H', 'Allopathy', 12, ['20mg','40mg'], ['TAB']],
    ['Metoprolol', 'Beta Blocker', 'H', 'Allopathy', 12, ['25mg','50mg'], ['TAB']],
    ['Atenolol', 'Beta Blocker', 'H', 'Allopathy', 12, ['25mg','50mg'], ['TAB']],
    ['Ramipril', 'ACE Inhibitor', 'H', 'Allopathy', 12, ['2.5mg','5mg'], ['TAB']],
    ['Atorvastatin', 'Statin', 'H', 'Allopathy', 12, ['10mg','20mg','40mg'], ['TAB']],
    ['Rosuvastatin', 'Statin', 'H', 'Allopathy', 12, ['5mg','10mg','20mg'], ['TAB']],
    ['Clopidogrel', 'Antiplatelet', 'H', 'Allopathy', 12, ['75mg'], ['TAB']],
    ['Furosemide', 'Diuretic', 'H', 'Allopathy', 12, ['40mg'], ['TAB','INJ']],
    ['Hydrochlorothiazide', 'Diuretic', 'H', 'Allopathy', 12, ['12.5mg','25mg'], ['TAB']],

    // --- Antidiabetic ---
    ['Metformin', 'Antidiabetic-Biguanide', 'H', 'Allopathy', 12, ['500mg','850mg','1000mg'], ['TAB']],
    ['Glimepiride', 'Antidiabetic-Sulfonylurea', 'H', 'Allopathy', 12, ['1mg','2mg','4mg'], ['TAB']],
    ['Gliclazide', 'Antidiabetic-Sulfonylurea', 'H', 'Allopathy', 12, ['40mg','80mg'], ['TAB']],
    ['Sitagliptin', 'Antidiabetic-DPP4', 'H', 'Allopathy', 12, ['50mg','100mg'], ['TAB']],
    ['Teneligliptin', 'Antidiabetic-DPP4', 'H', 'Allopathy', 12, ['20mg'], ['TAB']],
    ['Insulin (Human)', 'Antidiabetic-Insulin', 'H', 'Allopathy', 5, ['40IU','100IU'], ['INJ']],

    // --- CNS / Psychiatry ---
    ['Alprazolam', 'Anxiolytic', 'H1', 'Allopathy', 12, ['0.25mg','0.5mg','1mg'], ['TAB']],
    ['Clonazepam', 'Anxiolytic/Anticonvulsant', 'H1', 'Allopathy', 12, ['0.25mg','0.5mg'], ['TAB']],
    ['Diazepam', 'Anxiolytic', 'H1', 'Allopathy', 12, ['5mg','10mg'], ['TAB','INJ']],
    ['Zolpidem', 'Hypnotic', 'H1', 'Allopathy', 12, ['5mg','10mg'], ['TAB']],
    ['Escitalopram', 'Antidepressant-SSRI', 'H', 'Allopathy', 12, ['5mg','10mg'], ['TAB']],
    ['Sertraline', 'Antidepressant-SSRI', 'H', 'Allopathy', 12, ['25mg','50mg','100mg'], ['TAB']],
    ['Amitriptyline', 'Antidepressant-TCA', 'H', 'Allopathy', 12, ['10mg','25mg'], ['TAB']],
    ['Pregabalin', 'Neuropathic Pain', 'H', 'Allopathy', 12, ['50mg','75mg','150mg'], ['CAP']],
    ['Gabapentin', 'Neuropathic Pain', 'H', 'Allopathy', 12, ['100mg','300mg'], ['CAP','TAB']],
    ['Levetiracetam', 'Anticonvulsant', 'H', 'Allopathy', 12, ['250mg','500mg'], ['TAB']],
    ['Morphine', 'Opioid Analgesic', 'X', 'Allopathy', 12, ['10mg'], ['TAB','INJ']],
    ['Buprenorphine', 'Opioid', 'X', 'Allopathy', 12, ['0.2mg','2mg'], ['TAB','INJ']],

    // --- Steroids / Hormones / Thyroid ---
    ['Prednisolone', 'Corticosteroid', 'H', 'Allopathy', 12, ['5mg','10mg'], ['TAB','SYP']],
    ['Dexamethasone', 'Corticosteroid', 'H', 'Allopathy', 12, ['0.5mg','4mg'], ['TAB','INJ']],
    ['Betamethasone', 'Corticosteroid', 'H', 'Allopathy', 12, ['0.5mg'], ['TAB','OINT']],
    ['Levothyroxine', 'Thyroid Hormone', 'H', 'Allopathy', 12, ['25mcg','50mcg','100mcg'], ['TAB']],

    // --- Vitamins / Supplements (OTC) ---
    ['Vitamin C (Ascorbic Acid)', 'Vitamin', 'OTC', 'Allopathy', 12, ['500mg'], ['TAB']],
    ['Vitamin D3 (Cholecalciferol)', 'Vitamin', 'OTC', 'Allopathy', 12, ['1000IU','60000IU'], ['TAB','SYP']],
    ['Vitamin B-Complex', 'Vitamin', 'OTC', 'Allopathy', 12, ['Standard'], ['TAB','SYP']],
    ['Calcium + Vitamin D3', 'Mineral/Vitamin', 'OTC', 'Allopathy', 12, ['500mg'], ['TAB','SUSP']],
    ['Iron + Folic Acid', 'Hematinic', 'OTC', 'Allopathy', 12, ['Standard'], ['TAB','SYP']],
    ['Methylcobalamin', 'Vitamin B12', 'OTC', 'Allopathy', 12, ['500mcg','1500mcg'], ['TAB','INJ']],
    ['Multivitamin + Multimineral', 'Supplement', 'OTC', 'Allopathy', 12, ['Standard'], ['CAP','SYP']],
    ['Zinc + Vitamin C', 'Supplement', 'OTC', 'Allopathy', 12, ['Standard'], ['TAB']],

    // --- Topical / Dermatology (Cosmetic & Allopathy) ---
    ['Clotrimazole', 'Antifungal-Topical', 'OTC', 'Allopathy', 12, ['1%'], ['OINT']],
    ['Mometasone', 'Topical Steroid', 'H', 'Allopathy', 12, ['0.1%'], ['OINT']],
    ['Silver Sulfadiazine', 'Topical Antibacterial', 'H', 'Allopathy', 12, ['1%'], ['OINT']],
    ['Permethrin', 'Scabicide', 'OTC', 'Allopathy', 12, ['5%'], ['OINT']],
    ['Sunscreen SPF50', 'Dermo-cosmetic', 'OTC', 'Cosmetic', 18, ['SPF50'], ['OINT']],
    ['Moisturizing Lotion', 'Dermo-cosmetic', 'OTC', 'Cosmetic', 18, ['Standard'], ['OINT']],

    // --- Surgical / Misc ---
    ['Povidone Iodine', 'Antiseptic', 'OTC', 'Surgical', 12, ['5%','10%'], ['OINT','SYP']],
    ['Chlorhexidine', 'Antiseptic', 'OTC', 'Surgical', 12, ['Standard'], ['SYP']],

    // --- Ayurvedic ---
    ['Ashwagandha', 'Adaptogen', 'OTC', 'Ayurvedic', 12, ['250mg','500mg'], ['TAB','CAP']],
    ['Triphala', 'Digestive', 'OTC', 'Ayurvedic', 12, ['500mg'], ['TAB','CAP']],
    ['Giloy (Guduchi)', 'Immunity', 'OTC', 'Ayurvedic', 12, ['500mg'], ['TAB','SYP']],
    ['Tulsi Drops', 'Immunity', 'OTC', 'Ayurvedic', 12, ['Standard'], ['DROP']],
    ['Shatavari', 'Tonic', 'OTC', 'Ayurvedic', 12, ['500mg'], ['CAP','SYP']],
    ['Brahmi', 'Nootropic', 'OTC', 'Ayurvedic', 12, ['250mg'], ['TAB','SYP']],
    ['Liver Tonic (Herbal)', 'Hepatoprotective', 'OTC', 'Ayurvedic', 12, ['Standard'], ['SYP','TAB']],
    ['Chyawanprash', 'Immunity', 'OTC', 'Ayurvedic', 12, ['Standard'], ['SYP']],
];

$manufacturers = [
    'Cipla', 'Sun Pharma', 'Mankind Pharma', 'Alkem Laboratories', "Dr. Reddy's",
    'Lupin', 'Torrent Pharma', 'Zydus Cadila', 'Abbott India', 'GlaxoSmithKline',
    'Micro Labs', 'Intas Pharma', 'Glenmark', 'Aristo Pharma', 'Macleods Pharma',
    'Ipca Laboratories', 'Mankind', 'Emcure', 'Wockhardt', 'Cadila Pharma',
    'USV', 'Sanofi India', 'Pfizer India', 'Eris Lifesciences', 'Ajanta Pharma',
    'FDC Ltd', 'Hetero Drugs', 'Biocon', 'Indoco Remedies', 'Unichem',
    'Albert David', 'Bharat Serums', 'Mylan', 'Novartis India', 'Troikaa',
    'Bal Pharma', 'Khandelwal Lab', 'Leeford', 'Systopic', 'Walter Bushnell',
    'Akumentis', 'Corona Remedies', 'La Renon', 'Mankind Lifesciences', 'Stadmed',
    'Themis Medicare', 'Blue Cross', 'Centaur Pharma', 'East West Pharma', 'Zuventus',
    'Dabur', 'Himalaya', 'Patanjali', 'Baidyanath', 'Charak',
];

// Plausible brand roots (combined with strength/form to form brand names).
$brandRoots = [
    'Zicip','Dolo','Cefta','Azee','Moxi','Pantec','Calpol','Crocin','Combiflam','Allegra',
    'Monocef','Taxim','Augpen','Clavam','Mox','Novamox','Roxid','Zithrox','Cifran','Oflox',
    'Levoflox','Pantop','Pan','Razo','Nexpro','Omez','Domstal','Emeset','Rantac','Cyclopam',
    'Amlong','Telma','Losar','Olmat','Metolar','Aten','Cardace','Atorva','Rosuva','Clopid',
    'Lasix','Glycomet','Amaryl','Reclide','Istamet','Tenepride','Huminsulin','Restyl','Lonazep','Valium',
    'Zolfresh','Nexito','Zoloft','Amitone','Pregabid','Gabapin','Levipil','Morcontin','Norphin','Wysolone',
    'Dexona','Betnesol','Thyronorm','Limcee','Calcirol','Becosule','Shelcal','Orofer','Methycobal','Supradyn',
    'Zincovit','Candid','Momate','Silverex','Permite','Suncros','Cetaphil','Betadine','Savlon','Ashwa',
    'Triphal','Giloy','Tulsi','Shatari','Brahmi','Livcare','Chyawan','Okacet','Hicet','Teczine',
    'Montair','Asthalin','Levolin','Mucolite','Deriphyllin','Zerodol','Hifenac','Nimek','Voveran','Naprosyn',
    'Ultracet','Etova','Ciplox','Zenflox','Norflox','Flagyl','Metrogyl','Ornof','Linospan','Niftran',
    'Faronem','Dalacin','Forcan','Itaspor','Acivir','Zentel','Defcort','Omnacortil','Eltroxin','Calcimax',
];

$forms = [
    'TAB'  => ['label' => 'Tablet',      'abbr' => 'Tab',    'uom' => 'STRIP', 'upp' => 10, 'pack' => '1x10 Tablets', 'mrpLow' => 18,  'mrpHigh' => 220],
    'CAP'  => ['label' => 'Capsule',     'abbr' => 'Cap',    'uom' => 'STRIP', 'upp' => 10, 'pack' => '1x10 Capsules','mrpLow' => 25,  'mrpHigh' => 280],
    'SYP'  => ['label' => 'Syrup',       'abbr' => 'Syrup',  'uom' => 'BOTTLE','upp' => 1,  'pack' => '100ml Bottle', 'mrpLow' => 40,  'mrpHigh' => 190],
    'SUSP' => ['label' => 'Suspension',  'abbr' => 'Susp',   'uom' => 'BOTTLE','upp' => 1,  'pack' => '60ml Bottle',  'mrpLow' => 45,  'mrpHigh' => 170],
    'INJ'  => ['label' => 'Injection',   'abbr' => 'Inj',    'uom' => 'VIAL',  'upp' => 1,  'pack' => '1 Vial',       'mrpLow' => 25,  'mrpHigh' => 650],
    'DROP' => ['label' => 'Drops',       'abbr' => 'Drops',  'uom' => 'BOTTLE','upp' => 1,  'pack' => '30ml Drops',   'mrpLow' => 30,  'mrpHigh' => 150],
    'OINT' => ['label' => 'Cream',       'abbr' => 'Cream',  'uom' => 'TUBE',  'upp' => 1,  'pack' => '15g Tube',     'mrpLow' => 50,  'mrpHigh' => 260],
];

// ----------------------------------------------------------------------------
// 1) Generics (molecule + strength)
// ----------------------------------------------------------------------------
echo "Seeding generics...\n";
$genericMap = [];
$gStmt = $pdo->prepare(
    'INSERT INTO generics (name, therapeutic_class, is_active, created_at, updated_at)
     VALUES (:name, :class, 1, :now, :now2)'
);
$pdo->beginTransaction();
foreach ($molecules as $m) {
    foreach ($m[5] as $strength) {
        $gname = $m[0] . ' ' . $strength;
        $gStmt->execute(['name' => $gname, 'class' => $m[1], 'now' => $now, 'now2' => $now]);
        $genericMap[$m[0] . '|' . $strength] = (int) $pdo->lastInsertId();
    }
}
$pdo->commit();
echo '  generics inserted: ' . count($genericMap) . "\n";

// ----------------------------------------------------------------------------
// 2) Branded products
// ----------------------------------------------------------------------------
echo "Seeding {$target} products...\n";

$cols = ['name','generic_id','manufacturer','schedule_category','product_category','hsn_code',
         'default_gst_rate','ptr','pts','pack_size','units_per_pack','allow_loose','shelf_location',
         'uom','composition','is_active','created_at','updated_at'];
$rowPlaceholder = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';

$batchSize = 500;
$buffer = [];
$count = 0;

// margins for auto PTR/PTS by category
$marginMap = [
    'Allopathy' => [20, 30], 'Ayurvedic' => [25, 35], 'Generic' => [40, 55],
    'Surgical' => [15, 22], 'Cosmetic' => [18, 28],
];

$flush = function () use (&$buffer, &$cols, $pdo, $rowPlaceholder) {
    if (!$buffer) return;
    $sql = 'INSERT INTO products (' . implode(',', $cols) . ') VALUES '
         . implode(',', array_fill(0, count($buffer), $rowPlaceholder));
    $params = [];
    foreach ($buffer as $row) {
        foreach ($row as $v) $params[] = $v;
    }
    $pdo->prepare($sql)->execute($params);
    $buffer = [];
};

$mCount = count($molecules);
$fCount = count($manufacturers);
$bCount = count($brandRoots);
$shelves = ['A1','A2','A3','B1','B2','B3','C1','C2','C3','D1','D2','E1','F1','G1','H1'];

$pdo->beginTransaction();
$i = 0;
while ($count < $target) {
    $m = $molecules[$i % $mCount];
    [$mol, $class, $schedule, $category, $gst, $strengths, $mForms] = $m;

    $strength = $strengths[intdiv($i, $mCount) % count($strengths)];
    $formKey  = $mForms[$i % count($mForms)];
    $form     = $forms[$formKey];
    $mfr      = $manufacturers[$i % $fCount];
    $root     = $brandRoots[$i % $bCount];
    $gid      = $genericMap[$mol . '|' . $strength] ?? null;

    // Brand name (duplicates allowed across strengths/forms, like real catalogues).
    $name = $root . ' ' . $strength . ' ' . $form['abbr'];

    // MRP: realistic range per form with mild strength influence.
    $span = $form['mrpHigh'] - $form['mrpLow'];
    $mrp  = round($form['mrpLow'] + (mt_rand(0, 1000) / 1000) * $span, 0) + 0.0;
    if ($mrp < 5) $mrp = 5.0;

    // Auto PTR/PTS (excl GST) from margin.
    [$ptrM, $ptsM] = $marginMap[$category] ?? [20, 30];
    $netMrp = $mrp / (1 + $gst / 100);
    $ptr = round($netMrp / (1 + $ptrM / 100), 2);
    $pts = round($netMrp / (1 + $ptsM / 100), 2);

    $buffer[] = [
        $name, $gid, $mfr, $schedule, $category, '3004',
        $gst, $ptr, $pts, $form['pack'], $form['upp'], 1, $shelves[$i % count($shelves)],
        $form['uom'], $mol . ' ' . $strength, 1, $now, $now,
    ];
    $count++;
    $i++;

    if (count($buffer) >= $batchSize) {
        $flush();
    }
}
$flush();
$pdo->commit();

$total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$gtotal = (int) $pdo->query('SELECT COUNT(*) FROM generics')->fetchColumn();
echo "Done. products total = {$total}, generics total = {$gtotal}\n";
