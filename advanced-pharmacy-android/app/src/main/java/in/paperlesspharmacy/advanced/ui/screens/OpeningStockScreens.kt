package `in`.paperlesspharmacy.advanced.ui.screens

import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.AddCircle
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.DeleteSweep
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.FileDownload
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.RestartAlt
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.UploadFile
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.transaction.ImportRepository
import `in`.paperlesspharmacy.advanced.data.transaction.StockRepository
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.CompactScreenPadding
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaBorder
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaMuted
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaSlate
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTeal
import `in`.paperlesspharmacy.advanced.ui.theme.SalesDenseFieldHeight
import `in`.paperlesspharmacy.advanced.ui.util.formatAmount2
import `in`.paperlesspharmacy.advanced.ui.util.formatSaleDate
import `in`.paperlesspharmacy.advanced.util.ImportFileTools
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.ByteArrayOutputStream
import java.time.LocalDate
import java.time.YearMonth
import java.time.temporal.ChronoUnit
import java.util.concurrent.atomic.AtomicLong
import java.util.zip.ZipEntry
import java.util.zip.ZipOutputStream
import kotlin.math.abs
import kotlin.math.floor
import kotlin.math.max

// ───────────────────────── Models ─────────────────────────

private data class OsProduct(
    val id: Int,
    val name: String,
    val hsn: String = "",
    val gst: Double = 0.0,
    val pack: String = "",
    val mrp: Double = 0.0,
    val mfr: String = "",
    val sched: String = "",
    val shelf: String = "",
)

private fun osProduct(o: JsonObject): OsProduct = OsProduct(
    id = o.jInt("id"),
    name = o.str("name"),
    hsn = o.str("hsn_code"),
    gst = if (o.has("default_gst_rate")) o.amount("default_gst_rate") else o.amount("gst_rate"),
    pack = o.str("pack_size"),
    mrp = o.amount("mrp"),
    mfr = o.str("manufacturer_short").ifBlank { o.str("manufacturer") },
    sched = o.str("schedule_category"),
    shelf = o.str("shelf_location"),
)

private val osLineKeys = AtomicLong(1)

private data class OsLine(
    val key: Long = osLineKeys.getAndIncrement(),
    val masterId: Int = 0,
    val name: String,
    /** Name as typed / in the Excel file (used for matching). */
    val srcName: String = name,
    val pNo: String = "",
    val hsn: String = "",
    val batch: String = "",
    /** yyyy-MM (or raw text from Excel if it could not be read). */
    val expiry: String = "",
    val shelf: String = "",
    val qty: Double = 0.0,
    val mrp: Double = 0.0,
    val rate: Double = 0.0,
    val gst: Double = 5.0,
    val pack: String = "",
    val mfr: String = "",
    val cat: String = "",
    val matched: Boolean = false,
    val candidates: List<OsProduct> = emptyList(),
) {
    val amount: Double get() = osR2(rate * qty)
}

private data class OsDraft(
    val product: OsProduct? = null,
    val query: String = "",
    val batch: String = "",
    val expiryText: String = "",
    val qty: Double = 0.0,
    val mrp: Double = 0.0,
    val rate: Double = 0.0,
    val gst: Double = 5.0,
    val hsn: String = "",
    val shelf: String = "",
) {
    val dirty: Boolean get() = product != null || query.isNotBlank() || batch.isNotBlank() || qty > 0
}

private class OsMatchIndex(products: List<OsProduct>) {
    val exact = HashMap<String, OsProduct>()
    val prefix = HashMap<String, MutableList<OsProduct>>()

    init {
        for (p in products) {
            val n = osNorm(p.name)
            if (n.isEmpty()) continue
            exact[n] = p
            for (len in intArrayOf(3, 2, 1)) {
                val k = n.take(len)
                if (k.isNotEmpty()) prefix.getOrPut(k) { mutableListOf() }.add(p)
            }
        }
    }
}

private class OsState {
    var draft by mutableStateOf(OsDraft())
    var lines by mutableStateOf<List<OsLine>>(emptyList())
    var unmatchedOnly by mutableStateOf(false)
    var masters by mutableStateOf<List<OsProduct>>(emptyList())
    var index by mutableStateOf<OsMatchIndex?>(null)
    var hsns by mutableStateOf<List<String>>(emptyList())
    var shelves by mutableStateOf<List<String>>(emptyList())
    var packs by mutableStateOf<List<JsonObject>>(emptyList())
    var manufacturers by mutableStateOf<List<String>>(emptyList())
    val dirty: Boolean get() = lines.isNotEmpty() || draft.dirty
}

private data class OsAdjRow(
    val batchId: Int,
    val name: String,
    val mfr: String,
    val batch: String,
    val pack: String,
    val upp: Int,
    val expiry: String,
    val shelf: String,
    val sysUnits: Double,
    val sysPacks: Double,
    val phyUnits: Double,
    val phyPacks: Double,
    val reason: String = "",
) {
    val unitDiff: Double get() = phyUnits - sysUnits
    val packDiff: Double get() = phyPacks - sysPacks
    val changed: Boolean get() = unitDiff != 0.0 || packDiff != 0.0
}

private val OsGstOptions = listOf(0.0, 5.0, 12.0, 18.0, 28.0, 40.0)
private val OsExcelHeaders = listOf("product", "batch_no", "expiry_date", "shelf", "qty", "mrp", "purchase_rate", "gst", "hsn")
private val OsAdjTypes = listOf(
    "PHYSICAL_COUNT" to "Physical count",
    "DAMAGE" to "Damage",
    "EXPIRY" to "Expiry write-off",
    "THEFT" to "Theft",
    "OTHER" to "Other",
)
private val OsQuickReasons = listOf(
    "Physical count mismatch",
    "Damaged / broken",
    "Expired stock write-off",
    "Theft / lost",
    "Wrong entry correction",
    "Free goods received",
)
private const val OS_REASON_MAX = 255
private const val OS_DEFAULT_MIN = 15
private val OsWarnBg = Color(0xFFFFFBEB)
private val OsWarnText = Color(0xFF92400E)
private val OsOkGreen = Color(0xFF16A34A)

// ───────────────────────── Helpers ─────────────────────────

private fun osR2(v: Double): Double = floor(v * 100.0 + 0.5) / 100.0

private fun osNorm(s: String): String = s.lowercase().replace(Regex("\\s+"), " ").trim()

private fun osNum(v: Double): String = pNumText(v, false).ifBlank { "0" }

private fun osSigned(v: Double): String = (if (v > 0) "+" else "") + osNum(v)

private fun osExpiryLabel(ym: String): String =
    if (Regex("^\\d{4}-\\d{2}$").matches(ym)) pExpiryDisplay(ym) else ym.ifBlank { "—" }

private fun osMinYm(): String = YearMonth.now().minusYears(1).toString()
private fun osMaxYm(): String = YearMonth.now().plusYears(10).toString()

private fun osLevenshtein(x: String, y: String): Int {
    if (x == y) return 0
    if (x.isEmpty()) return y.length
    if (y.isEmpty()) return x.length
    val a = if (x.length > y.length) y else x
    val b = if (x.length > y.length) x else y
    var prev = IntArray(b.length + 1) { it }
    var curr = IntArray(b.length + 1)
    for (i in 1..a.length) {
        curr[0] = i
        for (j in 1..b.length) {
            val cost = if (a[i - 1] == b[j - 1]) 0 else 1
            curr[j] = minOf(prev[j] + 1, curr[j - 1] + 1, prev[j - 1] + cost)
        }
        val t = prev; prev = curr; curr = t
    }
    return prev[b.length]
}

/** Web `fuzzyMatchProducts`: exact name, else prefix bucket scored by containment / edit distance (≥ 0.5). */
private fun osFuzzy(name: String, index: OsMatchIndex, topN: Int = 3): List<OsProduct> {
    val a = osNorm(name)
    if (a.isEmpty()) return emptyList()
    index.exact[a]?.let { return listOf(it) }
    var cands: List<OsProduct> = emptyList()
    for (len in intArrayOf(3, 2, 1)) {
        val k = a.take(len)
        if (k.isEmpty()) break
        val c = index.prefix[k].orEmpty()
        if (c.isNotEmpty()) {
            cands = if (c.size > 400) c.take(400) else c
            break
        }
    }
    val scored = mutableListOf<Pair<OsProduct, Double>>()
    for (p in cands) {
        val b = osNorm(p.name)
        if (b.isEmpty()) continue
        val score = when {
            b == a -> 1.0
            b.contains(a) || a.contains(b) -> 0.85
            abs(a.length - b.length) > max(a.length, b.length) * 0.45 -> continue
            else -> 1.0 - osLevenshtein(a, b).toDouble() / max(a.length, b.length)
        }
        if (score >= 0.5) scored += p to score
    }
    return scored.sortedByDescending { it.second }.take(topN).map { it.first }
}

private fun osApply(l: OsLine, p: OsProduct): OsLine = l.copy(
    masterId = p.id,
    name = p.name,
    pNo = p.id.toString(),
    pack = p.pack,
    mfr = p.mfr,
    cat = p.sched,
    hsn = p.hsn.ifBlank { l.hsn },
    gst = if (p.gst > 0) p.gst else if (l.gst > 0) l.gst else 5.0,
    mrp = if (p.mrp > 0 && l.mrp <= 0) p.mrp else l.mrp,
    matched = true,
)

private fun osMatch(l: OsLine, index: OsMatchIndex): OsLine {
    val m = osFuzzy(l.srcName, index)
    return if (m.isNotEmpty()) {
        osApply(l.copy(candidates = if (m.size > 1) m else emptyList()), m[0])
    } else {
        l.copy(matched = false, masterId = 0, candidates = emptyList())
    }
}

private fun osIssue(l: OsLine): String? = when {
    !l.matched || l.masterId <= 0 -> "Not matched"
    l.batch.isBlank() -> "Batch required"
    l.qty <= 0 -> "Qty required"
    l.mrp <= 0 -> "MRP required"
    l.rate <= 0 -> "Rate required"
    else -> null
}

private fun osExpiryFromCell(raw: String): String {
    val s = raw.trim()
    if (s.isEmpty()) return ""
    if (Regex("^\\d{4}-\\d{2}$").matches(s)) return s
    if (Regex("^\\d{4}-\\d{2}-\\d{2}").containsMatchIn(s)) return s.take(7)
    if (Regex("^\\d{4}/\\d{2}$").matches(s)) return s.replace('/', '-')
    Regex("^(\\d{1,2})[/-](\\d{1,2})[/-](\\d{2,4})$").find(s)?.let { m ->
        val y = m.groupValues[3].let { if (it.length == 2) "20$it" else it }
        return "$y-${m.groupValues[2].toInt().toString().padStart(2, '0')}"
    }
    if (Regex("^\\d{1,2}[/-](\\d{2}|\\d{4})$").matches(s)) return pExpiryYm(s).ifBlank { s }
    if (Regex("^\\d{5}(\\.\\d+)?$").matches(s)) {
        val days = s.toDouble().toLong()
        return YearMonth.from(LocalDate.of(1899, 12, 30).plusDays(days)).toString()
    }
    return s
}

private fun osParseCsv(text: String): List<List<String>> {
    val rows = mutableListOf<List<String>>()
    var row = mutableListOf<String>()
    val cell = StringBuilder()
    var quoted = false
    var i = 0
    while (i < text.length) {
        val c = text[i]
        if (quoted) {
            if (c == '"') {
                if (i + 1 < text.length && text[i + 1] == '"') { cell.append('"'); i++ } else quoted = false
            } else cell.append(c)
        } else when (c) {
            '"' -> quoted = true
            ',' -> { row += cell.toString(); cell.clear() }
            '\r' -> {}
            '\n' -> { row += cell.toString(); cell.clear(); rows += row; row = mutableListOf() }
            else -> cell.append(c)
        }
        i++
    }
    if (cell.isNotEmpty() || row.isNotEmpty()) { row += cell.toString(); rows += row }
    return rows
}

/** Web `parseOpeningExcel`: first sheet, required headers, one line per product row. */
private fun osParseOpeningFile(name: String, bytes: ByteArray): List<OsLine> {
    val lower = name.lowercase()
    val text = when {
        lower.endsWith(".xls") -> throw IllegalArgumentException("Legacy .xls is not supported. Save as .xlsx or .csv and try again.")
        lower.endsWith(".csv") || lower.endsWith(".txt") -> String(bytes, Charsets.UTF_8).removePrefix("\uFEFF")
        else -> ImportFileTools.xlsxToCsv(bytes)
    }
    val rows = osParseCsv(text).filter { r -> r.any { it.isNotBlank() } }
    if (rows.size < 2) throw IllegalArgumentException("Excel file is empty or has no data rows.")
    val header = rows[0].mapIndexed { i, h -> h.trim().lowercase().replace(Regex("\\s+"), "_") to i }.toMap()
    val missing = OsExcelHeaders.filter { it !in header }
    if (missing.isNotEmpty()) throw IllegalArgumentException("Missing required columns: " + missing.joinToString(", "))
    fun cell(r: List<String>, key: String): String = r.getOrNull(header.getValue(key)).orEmpty().trim()
    fun num(r: List<String>, key: String): Double = cell(r, key).replace(",", "").replace("%", "").toDoubleOrNull() ?: 0.0
    val out = rows.drop(1).mapNotNull { r ->
        val product = cell(r, "product")
        if (product.isEmpty()) return@mapNotNull null
        val gstRaw = cell(r, "gst").replace(",", "").replace("%", "").toDoubleOrNull()
        OsLine(
            name = product,
            srcName = product,
            batch = cell(r, "batch_no"),
            expiry = osExpiryFromCell(cell(r, "expiry_date")),
            shelf = cell(r, "shelf"),
            qty = num(r, "qty"),
            mrp = num(r, "mrp"),
            rate = num(r, "purchase_rate"),
            gst = gstRaw ?: 5.0,
            hsn = cell(r, "hsn"),
        )
    }
    if (out.isEmpty()) throw IllegalArgumentException("No valid product rows found.")
    return out
}

private fun osXml(s: String): String =
    s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\"", "&quot;")

/** Minimal .xlsx with the same headers + sample row as the web template. */
private fun osTemplateXlsx(): ByteArray {
    val rows = listOf(
        OsExcelHeaders,
        listOf("DOLO 650 TAB", "BATCH001", "2028-05", "A1", 10, 30, 25, 12, "30049099"),
    )
    val sheet = StringBuilder("""<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>""")
    rows.forEachIndexed { ri, r ->
        sheet.append("<row r=\"${ri + 1}\">")
        r.forEachIndexed { ci, v ->
            val ref = "${'A' + ci}${ri + 1}"
            if (v is Number) sheet.append("<c r=\"$ref\"><v>$v</v></c>")
            else sheet.append("<c r=\"$ref\" t=\"inlineStr\"><is><t>${osXml(v.toString())}</t></is></c>")
        }
        sheet.append("</row>")
    }
    sheet.append("</sheetData></worksheet>")
    val files = linkedMapOf(
        "[Content_Types].xml" to """<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>""",
        "_rels/.rels" to """<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>""",
        "xl/workbook.xml" to """<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Opening Stock" sheetId="1" r:id="rId1"/></sheets></workbook>""",
        "xl/_rels/workbook.xml.rels" to """<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>""",
        "xl/worksheets/sheet1.xml" to sheet.toString(),
    )
    val bos = ByteArrayOutputStream()
    ZipOutputStream(bos).use { zip ->
        files.forEach { (path, xml) ->
            zip.putNextEntry(ZipEntry(path))
            zip.write(xml.toByteArray(Charsets.UTF_8))
            zip.closeEntry()
        }
    }
    return bos.toByteArray()
}

private fun osErr(t: Throwable, fallback: String): String = t.message?.takeIf { it.isNotBlank() } ?: fallback

// ───────────────────────── Module ─────────────────────────

@Composable
fun OpeningStockModuleScreen(
    vm: AppViewModel,
    onBack: () -> Unit,
    onOpenReport: (String) -> Unit = {},
    initialTab: Int = 0,
) {
    val repo = remember { vm.stock() }
    val imports = remember { vm.imports() }
    val canEdit = vm.canEdit("OPENING_STOCK")
    val canDelete = vm.canDelete("OPENING_STOCK") || vm.hasFullAccess()
    val canAudit = vm.canView("ALL_REPORTS")
    val st = remember { OsState() }
    var tab by rememberSaveable { mutableIntStateOf(initialTab.coerceIn(0, 2)) }
    var confirmLeave by remember { mutableStateOf(false) }

    // Dashboard "Set Min" / Min Stock can open this screen with tab=2.
    LaunchedEffect(initialTab) {
        tab = initialTab.coerceIn(0, 2)
    }

    LaunchedEffect(Unit) {
        launch {
            runCatching { withContext(Dispatchers.IO) { repo.openingMatchProducts() } }.onSuccess { rows ->
                val list = rows.map(::osProduct).filter { it.id > 0 && it.name.isNotBlank() }
                st.masters = list
                st.index = withContext(Dispatchers.Default) { OsMatchIndex(list) }
            }
        }
        launch { runCatching { withContext(Dispatchers.IO) { repo.hsnCodes() } }.onSuccess { st.hsns = it } }
        launch {
            runCatching { withContext(Dispatchers.IO) { repo.searchProducts("", 500) } }.onSuccess { rows ->
                st.shelves = rows.map { it.str("shelf_location").trim() }.filter { it.isNotEmpty() }.distinct().sorted()
            }
        }
        launch { runCatching { withContext(Dispatchers.IO) { repo.packSizes() } }.onSuccess { st.packs = it } }
        launch { runCatching { withContext(Dispatchers.IO) { imports.manufacturers() } }.onSuccess { st.manufacturers = it } }
    }

    fun leave() {
        if (st.dirty) confirmLeave = true else onBack()
    }
    BackHandler(enabled = st.dirty) { confirmLeave = true }

    Column(Modifier.fillMaxSize()) {
        Column(
            Modifier.padding(start = CompactScreenPadding, end = CompactScreenPadding, top = CompactScreenPadding),
            verticalArrangement = Arrangement.spacedBy(5.dp),
        ) {
            PoHeader(
                "Opening Stock",
                when (tab) {
                    0 -> "Search product, add lines, save in one document. Taxable = Rate × Qty (no GST split)."
                    1 -> "Physical count vs system stock — add or deduct automatically from the difference."
                    else -> "Set a minimum qty per product — low stock shows on the Dashboard and in Auto-Purchase."
                },
                onBack = { leave() },
            )
            Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                PoChip("Opening Stock" + if (st.lines.isNotEmpty()) " (${st.lines.size})" else "", tab == 0) { tab = 0 }
                PoChip("Stock Adjustment", tab == 1) { tab = 1 }
                PoChip("Minimum Inventory", tab == 2) { tab = 2 }
            }
        }
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when (tab) {
                0 -> OsOpeningTab(vm, repo, imports, st, canEdit, canDelete)
                1 -> OsAdjustmentTab(repo, canEdit, canAudit) { onOpenReport("stock-adjustment-audit") }
                else -> OsMinInventoryTab(repo, canEdit)
            }
        }
    }

    if (confirmLeave) {
        AlertDialog(
            onDismissRequest = { confirmLeave = false },
            title = { Text("Leave opening stock?", style = MaterialTheme.typography.titleSmall) },
            text = {
                Text(
                    "${st.lines.size} unsaved line(s) and the entry form will be cleared.",
                    style = MaterialTheme.typography.bodySmall,
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmLeave = false
                    st.lines = emptyList()
                    st.draft = OsDraft()
                    onBack()
                }) { Text("Discard", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmLeave = false }) { Text("Stay") } },
        )
    }
}

// ───────────────────────── Opening stock tab ─────────────────────────

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OsOpeningTab(
    vm: AppViewModel,
    repo: StockRepository,
    imports: ImportRepository,
    st: OsState,
    canEdit: Boolean,
    canDelete: Boolean,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current
    val wide = LocalConfiguration.current.screenWidthDp >= 600
    val batchFocus = remember { FocusRequester() }

    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var listLoading by remember { mutableStateOf(true) }
    var listTick by remember { mutableIntStateOf(0) }
    var savedSearch by remember { mutableStateOf("") }
    var savedOpen by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    var excelBusy by remember { mutableStateOf(false) }
    var hits by remember { mutableStateOf<List<OsProduct>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    var resultsOpen by remember { mutableStateOf(false) }
    var pendingImport by remember { mutableStateOf<List<OsLine>?>(null) }
    var quickProduct by remember { mutableStateOf(false) }
    var quickProductName by remember { mutableStateOf("") }
    var pendingProductLineKey by remember { mutableStateOf<Long?>(null) }
    var matchFor by remember { mutableStateOf<Long?>(null) }
    val canQuickAddMaster = vm.canEdit("MASTERS") || vm.hasFullAccess()
    var editLineFor by remember { mutableStateOf<Long?>(null) }
    var pickSheet by remember { mutableStateOf<String?>(null) }
    var editSaved by remember { mutableStateOf<JsonObject?>(null) }
    var confirmDelete by remember { mutableStateOf<JsonObject?>(null) }
    var deletingId by remember { mutableIntStateOf(0) }
    var confirmWriteOff by remember { mutableStateOf(false) }
    var writingOff by remember { mutableStateOf(false) }

    LaunchedEffect(listTick) {
        listLoading = true
        runCatching { withContext(Dispatchers.IO) { repo.openingList() } }
            .onSuccess { rows = it }
            .onFailure { error = osErr(it, "Could not load opening stock.") }
        listLoading = false
    }
    LaunchedEffect(toast) {
        if (toast != null) {
            delay(4000)
            toast = null
        }
    }

    val d = st.draft
    LaunchedEffect(d.query, d.product) {
        if (d.product != null) return@LaunchedEffect
        val q = d.query.trim()
        if (q.length < 2) {
            hits = emptyList()
            searching = false
            resultsOpen = false
            return@LaunchedEffect
        }
        resultsOpen = true
        searching = true
        delay(300)
        runCatching { withContext(Dispatchers.IO) { repo.searchProducts(q, 40) } }
            .onSuccess { hits = it.map(::osProduct) }
            .onFailure { hits = emptyList() }
        searching = false
    }

    fun selectProduct(p: OsProduct) {
        val cur = st.draft
        st.draft = cur.copy(
            product = p,
            query = p.name,
            hsn = p.hsn.ifBlank { cur.hsn },
            gst = if (p.gst > 0) p.gst else 5.0,
            shelf = p.shelf.ifBlank { cur.shelf },
            mrp = if (p.mrp > 0) p.mrp else cur.mrp,
        )
        resultsOpen = false
        error = null
        scope.launch {
            delay(60)
            runCatching { batchFocus.requestFocus() }
        }
    }

    fun addLine() {
        val cur = st.draft
        val ym = pExpiryYm(cur.expiryText)
        if (cur.query.isBlank() || cur.batch.isBlank() || ym.isEmpty() || cur.qty <= 0 || cur.mrp <= 0 || cur.rate <= 0) {
            error = "Product, Batch, Expiry, Qty, MRP and Rate are required."
            return
        }
        val p = cur.product ?: run {
            error = "Select a product from search or add it in Product Master."
            return
        }
        val minYm = osMinYm()
        val maxYm = osMaxYm()
        if (ym < minYm || ym > maxYm) {
            error = "Expiry must be between ${pExpiryDisplay(minYm)} and ${pExpiryDisplay(maxYm)}."
            return
        }
        error = null
        st.lines = st.lines + OsLine(
            masterId = p.id,
            name = p.name,
            pNo = p.id.toString(),
            hsn = cur.hsn,
            batch = cur.batch.trim(),
            expiry = ym,
            shelf = cur.shelf.trim(),
            qty = cur.qty,
            mrp = cur.mrp,
            rate = cur.rate,
            gst = cur.gst,
            pack = p.pack,
            mfr = p.mfr,
            cat = p.sched,
            matched = true,
        )
        st.draft = OsDraft()
        hits = emptyList()
        focus.clearFocus()
    }

    fun saveBulk() {
        val list = st.lines
        if (list.isEmpty()) { error = "At least 1 product is required."; return }
        val unmatched = list.count { !it.matched || it.masterId <= 0 }
        if (unmatched > 0) {
            error = "$unmatched product(s) are not matched. Match or add them before saving."
            st.unmatchedOnly = true
            return
        }
        list.forEachIndexed { i, l ->
            osIssue(l)?.let {
                error = "Row ${i + 1} (${l.name}): $it."
                return
            }
        }
        saving = true
        error = null
        val body = JsonObject().apply {
            addProperty("opening_date", LocalDate.now().toString())
            add(
                "items",
                JsonArray().apply {
                    list.forEach { l ->
                        add(
                            JsonObject().apply {
                                addProperty("master_product_id", l.masterId)
                                addProperty("batch_no", l.batch)
                                addProperty("expiry_date", l.expiry)
                                addProperty("shelf_location", l.shelf)
                                addProperty("qty", l.qty)
                                addProperty("mrp", l.mrp)
                                addProperty("purchase_rate", l.rate)
                                addProperty("gst_rate", l.gst)
                                addProperty("hsn_code", l.hsn)
                                addProperty("pack_size", l.pack)
                            },
                        )
                    }
                },
            )
        }
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.openingBulkSave(body) } }
                    .onSuccess { res ->
                        st.lines = emptyList()
                        st.unmatchedOnly = false
                        toast = "Opening stock saved (${res.str("ref_no")})."
                        listTick++
                    }
                    .onFailure { error = osErr(it, "Could not save opening stock.") }
            } finally {
                saving = false
            }
        }
    }

    suspend fun ensureIndex(): OsMatchIndex {
        st.index?.let { return it }
        val rowsM = withContext(Dispatchers.IO) { repo.openingMatchProducts() }
        val list = rowsM.map(::osProduct).filter { it.id > 0 && it.name.isNotBlank() }
        st.masters = list
        return withContext(Dispatchers.Default) { OsMatchIndex(list) }.also { st.index = it }
    }

    fun appendImported(imported: List<OsLine>) {
        st.lines = st.lines + imported
        val unmatched = imported.count { !it.matched }
        toast = "Imported ${imported.size} row(s)" + if (unmatched > 0) " — $unmatched need product match." else " — all matched."
        if (unmatched > 0) st.unmatchedOnly = true
    }

    val excelPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        excelBusy = true
        error = null
        scope.launch {
            runCatching {
                val name = withContext(Dispatchers.IO) {
                    context.contentResolver.query(uri, null, null, null, null)?.use { c ->
                        val idx = c.getColumnIndex(android.provider.OpenableColumns.DISPLAY_NAME)
                        if (idx >= 0 && c.moveToFirst()) c.getString(idx) else null
                    } ?: "opening.xlsx"
                }
                val bytes = withContext(Dispatchers.IO) {
                    context.contentResolver.openInputStream(uri)?.use { it.readBytes() }
                        ?: throw IllegalArgumentException("Could not read the file.")
                }
                val index = ensureIndex()
                withContext(Dispatchers.Default) { osParseOpeningFile(name, bytes).map { osMatch(it, index) } }
            }.onSuccess { imported ->
                if (st.lines.isNotEmpty()) pendingImport = imported else appendImported(imported)
            }.onFailure { error = osErr(it, "Failed to read Excel.") }
            excelBusy = false
        }
    }

    val templateSaver = rememberLauncherForActivityResult(
        ActivityResultContracts.CreateDocument("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"),
    ) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    context.contentResolver.openOutputStream(uri)?.use { it.write(osTemplateXlsx()) }
                        ?: throw IllegalStateException("Could not save the template.")
                }
            }.onSuccess { toast = "Template saved — fill it in Excel and use Upload Excel." }
                .onFailure { error = osErr(it, "Could not save the template.") }
        }
    }

    fun writeOff() {
        writingOff = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.writeOffExpired() } }
                .onSuccess { res ->
                    toast = "Wrote off ${res.obj("data").jInt("batches")} batch(es)."
                    listTick++
                }
                .onFailure { error = osErr(it, "Could not write off expired stock.") }
            writingOff = false
        }
    }

    fun deleteSaved(r: JsonObject) {
        val id = r.jInt("batch_id")
        deletingId = id
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.openingDelete(id) } }
                .onSuccess {
                    toast = "Deleted."
                    listTick++
                }
                .onFailure { error = osErr(it, "Delete failed.") }
            deletingId = 0
        }
    }

    val kpis = remember(rows) {
        var qty = 0.0
        var cost = 0.0
        var expiring = 0
        val today = LocalDate.now()
        rows.forEach { r ->
            val q = r.amount("quantity")
            qty += q
            cost += q * r.amount("net_rate")
            runCatching { LocalDate.parse(r.str("expiry_date").take(10)) }.getOrNull()?.let { exp ->
                val days = ChronoUnit.DAYS.between(today, exp)
                if (days in 0..90) expiring++
            }
        }
        listOf(
            "Saved batches" to rows.size.toString(),
            "Total qty" to osNum(qty),
            "Cost value" to formatAmount2(osR2(cost)),
            "Expiring ≤90d" to expiring.toString(),
        )
    }
    val savedFiltered = remember(rows, savedSearch) {
        val q = savedSearch.trim().lowercase()
        if (q.isEmpty()) rows else rows.filter {
            it.str("product_name").lowercase().contains(q) ||
                it.str("batch_no").lowercase().contains(q) ||
                it.str("note").lowercase().contains(q)
        }
    }
    val unmatchedCount = st.lines.count { !it.matched }
    val displayLines = if (st.unmatchedOnly) st.lines.filter { !it.matched } else st.lines
    val total = osR2(st.lines.sumOf { it.amount })
    val taxable = osR2(d.rate * d.qty)

    Column(Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.weight(1f).fillMaxWidth().padding(horizontal = CompactScreenPadding),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            item { Spacer(Modifier.height(2.dp)) }
            if (canEdit) {
                item {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        OsActionButton("Template", Icons.Default.FileDownload, Modifier.weight(1f), outlined = true) {
                            templateSaver.launch("opening_stock_template.xlsx")
                        }
                        OsActionButton(if (excelBusy) "Reading…" else "Upload Excel", Icons.Default.UploadFile, Modifier.weight(1f), enabled = !excelBusy) {
                            excelPicker.launch("*/*")
                        }
                        OsActionButton(
                            if (writingOff) "Writing off…" else "Write off expired",
                            Icons.Default.DeleteSweep,
                            Modifier.weight(1f),
                            outlined = true,
                            danger = true,
                            enabled = !writingOff,
                        ) { confirmWriteOff = true }
                    }
                }
            }
            toast?.let { t -> item { PoToast(t) { toast = null } } }
            error?.let { e -> item { ErrorBanner(e) { error = null } } }

            if (canEdit) {
                item {
                    PSection("New line", expanded = true, onToggle = null, summary = "") {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
                            PReadField("P.No", d.product?.id?.toString() ?: "—", Modifier.width(64.dp))
                            Column(Modifier.weight(1f)) {
                                PLabel("Product *")
                                Spacer(Modifier.height(1.dp))
                                SalesDenseSearchField(
                                    value = d.query,
                                    onValueChange = { st.draft = st.draft.copy(query = it, product = null); error = null },
                                    placeholder = "Search product (min 2 chars)…",
                                    modifier = Modifier.fillMaxWidth(),
                                    leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                                    trailingIcon = {
                                        when {
                                            searching -> CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                                            d.query.isNotEmpty() -> IconButton(onClick = { st.draft = st.draft.copy(query = "", product = null) }, modifier = Modifier.size(22.dp)) {
                                                Icon(Icons.Default.Close, "Clear", Modifier.size(14.dp), tint = PharmaMuted)
                                            }
                                        }
                                    },
                                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                                    keyboardActions = KeyboardActions(onSearch = {
                                        hits.firstOrNull()?.let { selectProduct(it) }
                                            ?: d.query.trim().takeIf { it.length >= 2 && canQuickAddMaster }?.let {
                                                quickProductName = it
                                                pendingProductLineKey = null
                                                quickProduct = true
                                            }
                                    }),
                                )
                            }
                        }
                        if (resultsOpen && d.product == null) {
                            OsProductResults(
                                hits = hits,
                                searching = searching,
                                query = d.query,
                                canAddNew = canQuickAddMaster,
                                onPick = { selectProduct(it) },
                                onAddNew = {
                                    quickProductName = d.query.trim()
                                    pendingProductLineKey = null
                                    quickProduct = true
                                },
                            )
                        }
                        val gridCols = if (wide) 4 else 2
                        val fields: List<@Composable (Modifier) -> Unit> = listOf(
                            { m ->
                                Column(m) {
                                    PLabel("Batch *")
                                    Spacer(Modifier.height(1.dp))
                                    SalesDenseSearchField(
                                        value = d.batch,
                                        onValueChange = { st.draft = st.draft.copy(batch = it.uppercase()) },
                                        placeholder = "Batch no",
                                        modifier = Modifier.fillMaxWidth().focusRequester(batchFocus),
                                        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
                                    )
                                }
                            },
                            { m -> OsExpiryField("Expiry *", d.expiryText, { st.draft = st.draft.copy(expiryText = it) }, m) },
                            { m -> PNumField("Qty (packs) *", d.qty, { st.draft = st.draft.copy(qty = it) }, m) },
                            { m -> PNumField("MRP *", d.mrp, { st.draft = st.draft.copy(mrp = it) }, m) },
                            { m -> PNumField("Rate *", d.rate, { st.draft = st.draft.copy(rate = it) }, m) },
                            { m ->
                                POptionDropdown(
                                    "GST",
                                    osNum(d.gst),
                                    OsGstOptions.map { osNum(it) },
                                    { st.draft = st.draft.copy(gst = it.toDoubleOrNull() ?: 0.0) },
                                    m,
                                    display = { "$it%" },
                                )
                            },
                            { m -> PPickField("HSN", d.hsn, "Select HSN", { pickSheet = "hsn" }, m) },
                            { m -> PPickField("Shelf", d.shelf, "Select / type shelf", { pickSheet = "shelf" }, m) },
                        )
                        fields.chunked(gridCols).forEach { chunk ->
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                chunk.forEach { f -> f(Modifier.weight(1f)) }
                                repeat(gridCols - chunk.size) { Spacer(Modifier.weight(1f)) }
                            }
                        }
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            PReadField("Taxable", formatAmount2(taxable), Modifier.weight(1f))
                            PReadField("Amount", formatAmount2(taxable), Modifier.weight(1f), highlight = true)
                            PReadField("Pack", d.product?.pack?.ifBlank { "—" } ?: "—", Modifier.weight(1f))
                            if (wide) PReadField("Mfr", d.product?.mfr?.ifBlank { "—" } ?: "—", Modifier.weight(1f))
                        }
                        if (!wide) PReadField("Mfr", d.product?.mfr?.ifBlank { "—" } ?: "—", Modifier.fillMaxWidth())
                        Button(
                            onClick = { addLine() },
                            modifier = Modifier.fillMaxWidth().height(38.dp),
                            shape = RoundedCornerShape(8.dp),
                            colors = ButtonDefaults.buttonColors(containerColor = OsOkGreen),
                        ) {
                            Icon(Icons.Default.Add, null, Modifier.size(18.dp))
                            Spacer(Modifier.width(4.dp))
                            Text("ADD", fontWeight = FontWeight.Bold)
                        }
                    }
                }

                item {
                    Row(Modifier.fillMaxWidth().padding(top = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text("PRODUCT LIST", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
                        if (unmatchedCount > 0) {
                            Spacer(Modifier.width(6.dp))
                            Text(
                                "$unmatchedCount unmatched",
                                Modifier.clip(RoundedCornerShape(10.dp)).background(Color(0xFFFEF3C7)).padding(horizontal = 7.dp, vertical = 1.dp),
                                style = MaterialTheme.typography.labelSmall,
                                fontWeight = FontWeight.SemiBold,
                                color = OsWarnText,
                            )
                        }
                        Spacer(Modifier.weight(1f))
                        Row(Modifier.clickable { st.unmatchedOnly = !st.unmatchedOnly }, verticalAlignment = Alignment.CenterVertically) {
                            Checkbox(checked = st.unmatchedOnly, onCheckedChange = { st.unmatchedOnly = it }, modifier = Modifier.size(30.dp))
                            Text("Unmatched only", style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                        }
                    }
                }
                if (displayLines.isEmpty()) {
                    item {
                        PharmaCard {
                            Text(
                                if (st.unmatchedOnly && st.lines.isNotEmpty()) "All lines are matched."
                                else "No lines yet — search a product and tap ADD, or use Upload Excel.",
                                Modifier.fillMaxWidth().padding(14.dp),
                                style = MaterialTheme.typography.labelSmall,
                                color = PharmaMuted,
                                textAlign = TextAlign.Center,
                            )
                        }
                    }
                } else {
                    val chunks = displayLines.chunked(if (wide) 2 else 1)
                    items(chunks, key = { c -> c.first().key }) { chunk ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            chunk.forEach { l ->
                                OsLineCard(
                                    index = st.lines.indexOfFirst { it.key == l.key },
                                    line = l,
                                    modifier = Modifier.weight(1f),
                                    onEdit = { editLineFor = l.key },
                                    onMatch = { matchFor = l.key },
                                    onRemove = { st.lines = st.lines.filter { it.key != l.key } },
                                )
                            }
                            if (chunk.size == 1 && wide) Spacer(Modifier.weight(1f))
                        }
                    }
                }
            }

            item {
                Spacer(Modifier.height(4.dp))
                val perRow = if (wide) 4 else 2
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    kpis.chunked(perRow).forEach { chunk ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                            chunk.forEach { (label, value) -> SalesLogKpiChip(label, if (listLoading) "—" else value, Modifier.weight(1f)) }
                        }
                    }
                }
            }
            item {
                PSection(
                    "Saved opening stock (remaining qty)",
                    expanded = savedOpen,
                    onToggle = { savedOpen = !savedOpen },
                    summary = "${rows.size} batch(es)",
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        SalesDenseSearchField(
                            value = savedSearch,
                            onValueChange = { savedSearch = it },
                            placeholder = "Filter by product, batch or ref…",
                            modifier = Modifier.weight(1f),
                            leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                        )
                        IconButton(onClick = { listTick++ }, modifier = Modifier.size(SalesDenseFieldHeight)) {
                            Icon(Icons.Default.Refresh, "Refresh", Modifier.size(18.dp), tint = PharmaSlate)
                        }
                    }
                    when {
                        listLoading && rows.isEmpty() -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                        }
                        savedFiltered.isEmpty() -> Text(
                            if (rows.isEmpty()) "No saved opening stock yet." else "No entries match the filter.",
                            Modifier.fillMaxWidth().padding(12.dp),
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                            textAlign = TextAlign.Center,
                        )
                    }
                }
            }
            if (savedOpen) {
                items(savedFiltered, key = { "saved-" + it.jInt("batch_id") }) { r ->
                    OsSavedCard(
                        r = r,
                        canEdit = canEdit,
                        canDelete = canDelete,
                        deleting = deletingId == r.jInt("batch_id"),
                        onEdit = { editSaved = r },
                        onDelete = { confirmDelete = r },
                    )
                }
            }
            item { Spacer(Modifier.height(12.dp)) }
        }

        if (canEdit && st.lines.isNotEmpty()) {
            PoBottomBar {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Column(Modifier.weight(1f)) {
                        Text("Total lines: ${st.lines.size}", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Text(
                            "Total Amount: ${formatAmount2(total)}" + if (unmatchedCount > 0) " · $unmatchedCount unmatched" else "",
                            style = MaterialTheme.typography.labelMedium,
                            color = if (unmatchedCount > 0) OsWarnText else PharmaTeal,
                            fontWeight = FontWeight.SemiBold,
                        )
                    }
                    PharmaPrimaryButton(if (saving) "Saving…" else "SAVE OPENING STOCK", enabled = !saving) { saveBulk() }
                }
            }
        }
    }

    // ── Dialogs & sheets ──
    pendingImport?.let { imported ->
        AlertDialog(
            onDismissRequest = { pendingImport = null },
            title = { Text("Import ${imported.size} row(s)?", style = MaterialTheme.typography.titleSmall) },
            text = { Text("They will be added to the current list.", style = MaterialTheme.typography.bodySmall) },
            confirmButton = {
                TextButton(onClick = {
                    pendingImport = null
                    appendImported(imported)
                }) { Text("Import") }
            },
            dismissButton = { TextButton(onClick = { pendingImport = null }) { Text("Cancel") } },
        )
    }
    if (confirmWriteOff) {
        AlertDialog(
            onDismissRequest = { confirmWriteOff = false },
            icon = { Icon(Icons.Default.Warning, null, tint = MaterialTheme.colorScheme.error) },
            title = { Text("Write off all expired stock?", style = MaterialTheme.typography.titleSmall) },
            text = { Text("Every batch past its expiry date will be reduced to zero.", style = MaterialTheme.typography.bodySmall) },
            confirmButton = {
                TextButton(onClick = {
                    confirmWriteOff = false
                    writeOff()
                }) { Text("Write off", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmWriteOff = false }) { Text("Cancel") } },
        )
    }
    confirmDelete?.let { r ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            icon = { Icon(Icons.Default.Delete, null, tint = MaterialTheme.colorScheme.error) },
            title = { Text("Delete opening stock?", style = MaterialTheme.typography.titleSmall) },
            text = { Text("“${r.str("product_name")}” · Batch ${r.str("batch_no").ifBlank { "—" }}", style = MaterialTheme.typography.bodySmall) },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = null
                    deleteSaved(r)
                }) { Text("Delete", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = null }) { Text("Cancel") } },
        )
    }
    editSaved?.let { r ->
        OsEditSavedSheet(
            row = r,
            onDismiss = { editSaved = null },
            onSave = { body, done ->
                scope.launch {
                    try {
                        runCatching { withContext(Dispatchers.IO) { repo.openingUpdate(r.jInt("batch_id"), body) } }
                            .onSuccess {
                                editSaved = null
                                toast = "Updated."
                                listTick++
                                done(null)
                            }
                            .onFailure { done(osErr(it, "Update failed.")) }
                    } catch (t: Throwable) {
                        done(osErr(t, "Update failed."))
                    }
                }
            },
        )
    }
    editLineFor?.let { key ->
        val line = st.lines.firstOrNull { it.key == key }
        if (line == null) editLineFor = null else OsEditLineSheet(
            line = line,
            hsns = st.hsns,
            shelves = st.shelves,
            onDismiss = { editLineFor = null },
            onSave = { updated ->
                st.lines = st.lines.map { if (it.key == key) updated else it }
                editLineFor = null
            },
        )
    }
    matchFor?.let { key ->
        val line = st.lines.firstOrNull { it.key == key }
        if (line == null) matchFor = null else OsMatchSheet(
            line = line,
            masters = st.masters,
            canAddNew = canQuickAddMaster,
            onDismiss = { matchFor = null },
            onPick = { p ->
                st.lines = st.lines.map { if (it.key == key) osApply(it, p) else it }
                matchFor = null
            },
            onAddNew = {
                matchFor = null
                quickProductName = line.srcName
                pendingProductLineKey = key
                quickProduct = true
            },
        )
    }
    pickSheet?.let { kind ->
        OsOptionSheet(
            title = if (kind == "hsn") "HSN code" else "Shelf",
            options = if (kind == "hsn") st.hsns else st.shelves,
            current = if (kind == "hsn") d.hsn else d.shelf,
            allowCustom = true,
            onDismiss = { pickSheet = null },
            onPick = { v ->
                st.draft = if (kind == "hsn") st.draft.copy(hsn = v) else st.draft.copy(shelf = v)
                pickSheet = null
            },
        )
    }
    if (quickProduct) {
        val nameSnapshot = quickProductName
        MasterQuickCreateSheet(
            vm = vm,
            masterKey = "products",
            prefillName = nameSnapshot,
            onDismiss = {
                quickProduct = false
                pendingProductLineKey = null
            },
            onCreated = {
                quickProduct = false
                scope.launch {
                    val lineKey = pendingProductLineKey
                    pendingProductLineKey = null
                    val rows = runCatching {
                        withContext(Dispatchers.IO) { repo.openingMatchProducts() }
                    }.getOrDefault(emptyList())
                    val list = rows.map(::osProduct).filter { it.id > 0 && it.name.isNotBlank() }
                    st.masters = list
                    st.index = withContext(Dispatchers.Default) { OsMatchIndex(list) }
                    val hit = list.firstOrNull { it.name.equals(nameSnapshot, ignoreCase = true) }
                        ?: list.firstOrNull { nameSnapshot.isNotBlank() && it.name.contains(nameSnapshot, ignoreCase = true) }
                        ?: runCatching {
                            withContext(Dispatchers.IO) { repo.searchProducts(nameSnapshot, 20) }
                        }.getOrDefault(emptyList()).map(::osProduct).firstOrNull()
                    if (hit != null) {
                        if (lineKey != null) {
                            val src = st.lines.firstOrNull { it.key == lineKey }
                            val srcKey = osNorm(src?.srcName ?: nameSnapshot)
                            st.lines = st.lines.map { l ->
                                if (l.key == lineKey || (!l.matched && osNorm(l.srcName) == srcKey)) osApply(l, hit) else l
                            }
                            toast = "Product added and matched to the line."
                        } else {
                            selectProduct(hit)
                            toast = "Product added and selected. Continue opening stock."
                        }
                    } else {
                        toast = "Product created. Search and select it to continue."
                        if (lineKey != null) matchFor = lineKey
                    }
                }
            },
        )
    }
}

@Composable
private fun OsActionButton(
    text: String,
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    modifier: Modifier = Modifier,
    outlined: Boolean = false,
    danger: Boolean = false,
    enabled: Boolean = true,
    onClick: () -> Unit,
) {
    val tint = if (danger) MaterialTheme.colorScheme.error else PharmaTeal
    val content: @Composable RowScope.() -> Unit = {
        Icon(icon, null, Modifier.size(16.dp))
        Spacer(Modifier.width(4.dp))
        Text(text, style = MaterialTheme.typography.labelMedium, maxLines = 2, textAlign = TextAlign.Center)
    }
    if (outlined) {
        OutlinedButton(
            onClick = onClick,
            enabled = enabled,
            modifier = modifier.heightIn(min = 38.dp),
            shape = RoundedCornerShape(8.dp),
            border = BorderStroke(1.dp, if (enabled) tint else PharmaBorder),
            colors = ButtonDefaults.outlinedButtonColors(contentColor = tint),
            contentPadding = PaddingValues(horizontal = 6.dp, vertical = 4.dp),
            content = content,
        )
    } else {
        Button(
            onClick = onClick,
            enabled = enabled,
            modifier = modifier.heightIn(min = 38.dp),
            shape = RoundedCornerShape(8.dp),
            colors = ButtonDefaults.buttonColors(
                containerColor = tint,
                disabledContainerColor = Color(0xFFCBD5E1),
                disabledContentColor = Color(0xFF475569),
            ),
            contentPadding = PaddingValues(horizontal = 6.dp, vertical = 4.dp),
            content = content,
        )
    }
}

@Composable
private fun OsExpiryField(label: String, text: String, onText: (String) -> Unit, modifier: Modifier = Modifier) {
    val ym = pExpiryYm(text)
    val bad = text.length == 5 && ym.isEmpty()
    ExpiryMmYyFormField(
        label = label,
        displayText = text,
        onDisplayText = onText,
        modifier = modifier,
        isError = bad,
    )
}

@Composable
private fun OsProductResults(
    hits: List<OsProduct>,
    searching: Boolean,
    query: String,
    canAddNew: Boolean,
    onPick: (OsProduct) -> Unit,
    onAddNew: () -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .border(1.dp, PFieldBorder, RoundedCornerShape(6.dp))
            .heightIn(max = 300.dp)
            .verticalScroll(rememberScrollState()),
    ) {
        if (hits.isEmpty()) {
            Text(
                if (searching) "Searching…" else "No product found for “${query.trim()}”.",
                Modifier.padding(10.dp),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
        }
        hits.forEach { p ->
            val sub = listOf(p.pack, p.mfr, p.sched, if (p.shelf.isNotBlank()) "Shelf ${p.shelf}" else "")
                .filter { it.isNotBlank() }.joinToString(" · ")
            Row(
                Modifier.fillMaxWidth().clickable { onPick(p) }.padding(horizontal = 8.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(Modifier.weight(1f)) {
                    Text(p.name, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                if (p.mrp > 0) Text("MRP ${formatAmount2(p.mrp)}", style = MaterialTheme.typography.labelSmall, color = PharmaTeal, fontWeight = FontWeight.SemiBold)
            }
            HorizontalDivider(color = PharmaBorder.copy(alpha = 0.4f))
        }
        if (canAddNew) {
            MasterAddNewRow(
                label = masterAddNewLabel("products", query),
                onClick = onAddNew,
            )
        }
    }
}

@Composable
private fun OsLineCard(
    index: Int,
    line: OsLine,
    modifier: Modifier,
    onEdit: () -> Unit,
    onMatch: () -> Unit,
    onRemove: () -> Unit,
) {
    val issue = osIssue(line)
    val unmatched = !line.matched
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = if (unmatched) OsWarnBg else Color.White,
        border = BorderStroke(
            1.dp,
            when {
                unmatched -> Color(0xFFF59E0B)
                issue != null -> MaterialTheme.colorScheme.error.copy(alpha = 0.6f)
                else -> PharmaBorder
            },
        ),
        modifier = modifier.clickable(onClick = onEdit),
    ) {
        Column(Modifier.padding(start = 8.dp, end = 2.dp, top = 4.dp, bottom = 6.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("${index + 1}", Modifier.width(20.dp), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                if (unmatched) {
                    Text(
                        "Match",
                        Modifier
                            .clip(RoundedCornerShape(6.dp))
                            .border(1.dp, Color(0xFFF59E0B), RoundedCornerShape(6.dp))
                            .clickable(onClick = onMatch)
                            .padding(horizontal = 8.dp, vertical = 2.dp),
                        style = MaterialTheme.typography.labelSmall,
                        fontWeight = FontWeight.Bold,
                        color = OsWarnText,
                    )
                } else {
                    Icon(Icons.Default.Check, "Matched", Modifier.size(16.dp).clickable(onClick = onMatch), tint = OsOkGreen)
                }
                Spacer(Modifier.width(6.dp))
                Text(
                    line.name,
                    Modifier.weight(1f),
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.SemiBold,
                    color = PharmaSlate,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                IconButton(onClick = onRemove, modifier = Modifier.size(30.dp)) {
                    Icon(Icons.Default.Close, "Remove", Modifier.size(16.dp), tint = MaterialTheme.colorScheme.error)
                }
            }
            if (unmatched && line.srcName != line.name) {
                Text("Excel: ${line.srcName}", Modifier.padding(start = 20.dp), style = MaterialTheme.typography.labelSmall, color = OsWarnText)
            }
            Text(
                listOf(
                    "Batch ${line.batch.ifBlank { "—" }}",
                    "Exp ${osExpiryLabel(line.expiry)}",
                    if (line.shelf.isNotBlank()) "Shelf ${line.shelf}" else "",
                    if (line.pNo.isNotBlank()) "P.No ${line.pNo}" else "",
                ).filter { it.isNotBlank() }.joinToString(" · "),
                Modifier.padding(start = 20.dp),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaSlate,
            )
            Text(
                listOf(
                    "MRP ${formatAmount2(line.mrp)}",
                    "GST ${osNum(line.gst)}%",
                    if (line.hsn.isNotBlank()) "HSN ${line.hsn}" else "",
                    line.pack,
                    line.mfr,
                ).filter { it.isNotBlank() }.joinToString(" · "),
                Modifier.padding(start = 20.dp),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Row(Modifier.padding(start = 20.dp, end = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "${osNum(line.qty)} × ${formatAmount2(line.rate)}",
                    Modifier.weight(1f),
                    style = MaterialTheme.typography.labelMedium,
                    color = PharmaSlate,
                )
                issue?.let {
                    Text(it, style = MaterialTheme.typography.labelSmall, color = if (unmatched) OsWarnText else MaterialTheme.colorScheme.error)
                    Spacer(Modifier.width(8.dp))
                }
                Text(formatAmount2(line.amount), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold, color = PharmaTeal)
            }
        }
    }
}

@Composable
private fun OsSavedCard(
    r: JsonObject,
    canEdit: Boolean,
    canDelete: Boolean,
    deleting: Boolean,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
) {
    val exp = r.str("expiry_date").take(10)
    val expLabel = runCatching { LocalDate.parse(exp).let { "%02d/%02d".format(it.monthValue, it.year % 100) } }.getOrDefault("—")
    Surface(shape = RoundedCornerShape(8.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(start = 10.dp, top = 6.dp, bottom = 6.dp, end = 2.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        r.str("product_name"),
                        Modifier.weight(1f, fill = false),
                        style = MaterialTheme.typography.bodySmall,
                        fontWeight = FontWeight.SemiBold,
                        color = PharmaSlate,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )
                    if (r.str("manufacturer_short").isNotBlank()) {
                        Text(" · ${r.str("manufacturer_short")}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1)
                    }
                }
                Text(
                    "${formatSaleDate(r.str("txn_date"))} · ${r.str("note").ifBlank { "—" }}",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
                Text(
                    "Batch ${r.str("batch_no").ifBlank { "—" }} · Exp $expLabel",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaSlate,
                )
                Text(
                    "Qty ${osNum(r.amount("quantity"))} · MRP ${if (r.has("mrp") && !r.get("mrp").isJsonNull) formatAmount2(r.amount("mrp")) else "—"} · Rate ${if (r.has("net_rate") && !r.get("net_rate").isJsonNull) formatAmount2(r.amount("net_rate")) else "—"}",
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = PharmaTeal,
                )
            }
            if (canEdit) {
                SalesLogIconAction(onClick = onEdit, contentDescription = "Edit") {
                    Icon(Icons.Default.Edit, null, Modifier.size(17.dp), tint = Color(0xFF0284C7))
                }
            }
            if (canDelete) {
                SalesLogIconAction(onClick = onDelete, contentDescription = "Delete") {
                    if (deleting) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                    else Icon(Icons.Default.Delete, null, Modifier.size(17.dp), tint = MaterialTheme.colorScheme.error)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OsEditSavedSheet(row: JsonObject, onDismiss: () -> Unit, onSave: (JsonObject, (String?) -> Unit) -> Unit) {
    var batch by remember { mutableStateOf(row.str("batch_no")) }
    var expiry by remember { mutableStateOf(row.str("expiry_date").take(10)) }
    var qty by remember { mutableDoubleStateOf(row.amount("quantity")) }
    var mrp by remember { mutableDoubleStateOf(row.amount("mrp")) }
    var rate by remember { mutableDoubleStateOf(row.amount("net_rate")) }
    var saving by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf<String?>(null) }

    ModalBottomSheet(onDismissRequest = { if (!saving) onDismiss() }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier.fillMaxWidth().imePadding().padding(horizontal = 12.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Text("Edit Opening Stock", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            err?.let { ErrorBanner(it) { err = null } }
            PReadField("Product", row.str("product_name"), Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                SalesFormField("Batch *", batch, { batch = it.uppercase() }, Modifier.weight(1f), isError = batch.isBlank())
                PDateField("Expiry", expiry, { expiry = it }, Modifier.weight(1f))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PNumField("Qty (units)", qty, { qty = it }, Modifier.weight(1f), isInt = true)
                PNumField("MRP / unit", mrp, { mrp = it }, Modifier.weight(1f))
                PNumField("Rate / unit", rate, { rate = it }, Modifier.weight(1f))
            }
            Text(
                "Quantity is the remaining stock in units (tablets / pieces). Changing it adjusts stock for this batch.",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End)) {
                PharmaOutlinedButton("Cancel", enabled = !saving, onClick = onDismiss)
                PharmaPrimaryButton(if (saving) "Saving…" else "Save", enabled = !saving) {
                    if (batch.isBlank()) {
                        err = "Batch is required."
                        return@PharmaPrimaryButton
                    }
                    saving = true
                    err = null
                    val body = JsonObject().apply {
                        addProperty("batch_no", batch.trim())
                        if (expiry.isBlank()) add("expiry_date", JsonNull.INSTANCE) else addProperty("expiry_date", expiry)
                        addProperty("quantity", qty.toLong())
                        addProperty("mrp", mrp)
                        addProperty("purchase_rate", rate)
                    }
                    try {
                        onSave(body) { e ->
                            saving = false
                            err = e
                        }
                    } catch (t: Throwable) {
                        saving = false
                        err = t.message ?: "Save failed."
                    }
                }
            }
            Spacer(Modifier.height(8.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OsEditLineSheet(
    line: OsLine,
    hsns: List<String>,
    shelves: List<String>,
    onDismiss: () -> Unit,
    onSave: (OsLine) -> Unit,
) {
    var batch by remember { mutableStateOf(line.batch) }
    var expText by remember { mutableStateOf(if (Regex("^\\d{4}-\\d{2}$").matches(line.expiry)) pExpiryDisplay(line.expiry) else "") }
    var qty by remember { mutableDoubleStateOf(line.qty) }
    var mrp by remember { mutableDoubleStateOf(line.mrp) }
    var rate by remember { mutableDoubleStateOf(line.rate) }
    var gst by remember { mutableDoubleStateOf(line.gst) }
    var hsn by remember { mutableStateOf(line.hsn) }
    var shelf by remember { mutableStateOf(line.shelf) }
    var pick by remember { mutableStateOf<String?>(null) }
    var err by remember { mutableStateOf<String?>(null) }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier.fillMaxWidth().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 12.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Text("Edit line", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            Text(line.name, style = MaterialTheme.typography.labelMedium, color = PharmaTeal, fontWeight = FontWeight.SemiBold)
            if (!Regex("^\\d{4}-\\d{2}$").matches(line.expiry) && line.expiry.isNotBlank()) {
                Text("Excel expiry “${line.expiry}” could not be read — enter MM/YY.", style = MaterialTheme.typography.labelSmall, color = OsWarnText)
            }
            err?.let { ErrorBanner(it) { err = null } }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                SalesFormField("Batch *", batch, { batch = it.uppercase() }, Modifier.weight(1f), isError = batch.isBlank())
                OsExpiryField("Expiry *", expText, { expText = it }, Modifier.weight(1f))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PNumField("Qty *", qty, { qty = it }, Modifier.weight(1f))
                PNumField("MRP *", mrp, { mrp = it }, Modifier.weight(1f))
                PNumField("Rate *", rate, { rate = it }, Modifier.weight(1f))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                POptionDropdown("GST", osNum(gst), OsGstOptions.map { osNum(it) }, { gst = it.toDoubleOrNull() ?: 0.0 }, Modifier.weight(1f), display = { "$it%" })
                PPickField("HSN", hsn, "Select HSN", { pick = "hsn" }, Modifier.weight(1f))
                PPickField("Shelf", shelf, "Shelf", { pick = "shelf" }, Modifier.weight(1f))
            }
            PReadField("Amount", formatAmount2(osR2(rate * qty)), Modifier.fillMaxWidth(), highlight = true)
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End)) {
                PharmaOutlinedButton("Cancel", onClick = onDismiss)
                PharmaPrimaryButton("Update line") {
                    val ym = pExpiryYm(expText)
                    err = when {
                        batch.isBlank() || ym.isEmpty() || qty <= 0 || mrp <= 0 || rate <= 0 -> "Batch, Expiry, Qty, MRP and Rate are required."
                        ym < osMinYm() || ym > osMaxYm() -> "Expiry must be between ${pExpiryDisplay(osMinYm())} and ${pExpiryDisplay(osMaxYm())}."
                        else -> null
                    }
                    if (err == null) {
                        onSave(line.copy(batch = batch.trim(), expiry = ym, qty = qty, mrp = mrp, rate = rate, gst = gst, hsn = hsn, shelf = shelf.trim()))
                    }
                }
            }
            Spacer(Modifier.height(8.dp))
        }
    }
    pick?.let { kind ->
        OsOptionSheet(
            title = if (kind == "hsn") "HSN code" else "Shelf",
            options = if (kind == "hsn") hsns else shelves,
            current = if (kind == "hsn") hsn else shelf,
            allowCustom = true,
            onDismiss = { pick = null },
            onPick = { v ->
                if (kind == "hsn") hsn = v else shelf = v
                pick = null
            },
        )
    }
}

@Composable
private fun OsMatchSheet(
    line: OsLine,
    masters: List<OsProduct>,
    canAddNew: Boolean,
    onDismiss: () -> Unit,
    onPick: (OsProduct) -> Unit,
    onAddNew: () -> Unit,
) {
    var q by remember { mutableStateOf(line.srcName) }
    val list = remember(q, masters) {
        val k = osNorm(q)
        val found = if (k.length < 2) emptyList() else masters.filter { osNorm(it.name).contains(k) }.take(60)
        (line.candidates + found).distinctBy { it.id }
    }
    PSearchSheet(
        title = "Match product",
        subtitle = "Excel / typed name: ${line.srcName}",
        query = q,
        onQuery = { q = it },
        placeholder = "Search Product Master…",
        loading = masters.isEmpty(),
        onDismiss = onDismiss,
    ) {
        if (list.isEmpty()) {
            item {
                Text(
                    if (masters.isEmpty()) "Loading Product Master…" else "No product matches “${q.trim()}”.",
                    Modifier.padding(10.dp),
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
            }
        }
        items(list, key = { it.id }) { p ->
            PSheetRow(
                p.name,
                listOf(p.pack, p.mfr, if (p.mrp > 0) "MRP ${formatAmount2(p.mrp)}" else "").filter { it.isNotBlank() }.joinToString(" · "),
                selected = p.id == line.masterId,
            ) { onPick(p) }
        }
        if (canAddNew) {
            item {
                MasterAddNewRow(
                    label = masterAddNewLabel("products", q.trim().ifBlank { line.srcName }),
                    onClick = onAddNew,
                )
            }
        }
    }
}

@Composable
private fun OsOptionSheet(
    title: String,
    options: List<String>,
    current: String,
    allowCustom: Boolean,
    onDismiss: () -> Unit,
    onPick: (String) -> Unit,
) {
    var q by remember { mutableStateOf("") }
    val filtered = remember(q, options) {
        val k = q.trim().lowercase()
        (if (k.isEmpty()) options else options.filter { it.lowercase().contains(k) }).take(200)
    }
    PSearchSheet(
        title = title,
        subtitle = if (current.isNotBlank()) "Current: $current" else null,
        query = q,
        onQuery = { q = it },
        placeholder = "Search or type…",
        loading = false,
        onDismiss = onDismiss,
    ) {
        if (current.isNotBlank()) {
            item { PSheetRow("Clear", "Leave this field empty", selected = false) { onPick("") } }
        }
        val typed = q.trim()
        if (allowCustom && typed.isNotEmpty() && options.none { it.equals(typed, ignoreCase = true) }) {
            item { PSheetRow("Use “${typed.uppercase()}”", "Not in the list — use as typed", selected = false) { onPick(typed.uppercase()) } }
        }
        items(filtered) { o -> PSheetRow(o, "", selected = o.equals(current, ignoreCase = true)) { onPick(o) } }
        if (filtered.isEmpty() && typed.isEmpty()) {
            item { Text("No options loaded.", Modifier.padding(10.dp), style = MaterialTheme.typography.labelSmall, color = PharmaMuted) }
        }
    }
}

@Composable
private fun OsPackSheet(packs: List<JsonObject>, current: String, onDismiss: () -> Unit, onPick: (String) -> Unit) {
    var q by remember { mutableStateOf("") }
    val key = pPackKey(q)
    val filtered = remember(q, packs) {
        if (q.isBlank()) packs.take(80)
        else packs.filter {
            val label = pPackLabel(it).lowercase()
            label.contains(q.lowercase()) || pPackKey(label).contains(key) || pPackKey(it.str("pack")).contains(key)
        }.take(80)
    }
    PSearchSheet(
        title = "Pack size",
        subtitle = if (current.isNotBlank()) "Current: $current" else "Choose or type a pack (e.g. 1x10, 100ML)",
        query = q,
        onQuery = { q = it },
        placeholder = "Search pack…",
        loading = false,
        onDismiss = onDismiss,
    ) {
        if (q.isNotBlank() && filtered.none { pPackKey(it.str("pack")) == key }) {
            item { PSheetRow("Use “${q.trim()}”", "Units per pack worked out from the label", selected = false) { onPick(q.trim()) } }
        }
        items(filtered) { p ->
            PSheetRow(
                title = pPackLabel(p),
                subtitle = listOf(p.jInt("units").takeIf { it > 0 }?.let { "$it unit(s)" }.orEmpty(), p.str("form")).filter { it.isNotBlank() }.joinToString(" · "),
                selected = pPackKey(p.str("pack")) == pPackKey(current),
                onClick = { onPick(p.str("pack").trim()) },
            )
        }
    }
}

// ───────────────────────── Stock adjustment tab ─────────────────────────

@Composable
private fun OsAdjustmentTab(repo: StockRepository, canEdit: Boolean, canAudit: Boolean, onAudit: () -> Unit) {
    val scope = rememberCoroutineScope()
    var adjType by rememberSaveable { mutableStateOf("PHYSICAL_COUNT") }
    var remarks by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    var rows by remember { mutableStateOf<List<OsAdjRow>>(emptyList()) }
    var loading by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var reasonTouched by remember { mutableStateOf(false) }
    var changedOnly by remember { mutableStateOf(false) }
    var tick by remember { mutableIntStateOf(0) }

    LaunchedEffect(tick) {
        loading = true
        error = null
        runCatching { withContext(Dispatchers.IO) { repo.adjustmentBatches(search.trim()) } }
            .onSuccess { list ->
                rows = list.map { p ->
                    val units = if (p.has("system_unit_qty")) p.amount("system_unit_qty") else p.amount("current_unit_qty")
                    val packs = if (p.has("system_qty")) p.amount("system_qty") else p.amount("current_qty")
                    val pack = p.str("pack_size")
                    OsAdjRow(
                        batchId = p.jInt("batch_id"),
                        name = p.str("p_name"),
                        mfr = p.str("m_name"),
                        batch = p.str("batch_no"),
                        pack = pack,
                        upp = max(1, p.jInt("units_per_pack").takeIf { it > 0 } ?: pUnitsFromPack(pack)),
                        expiry = p.str("expiry"),
                        shelf = p.str("shelf"),
                        sysUnits = units,
                        sysPacks = packs,
                        phyUnits = units,
                        phyPacks = packs,
                    )
                }
            }
            .onFailure {
                rows = emptyList()
                error = osErr(it, "Failed to load stock for adjustment.")
            }
        loading = false
    }
    LaunchedEffect(toast) {
        if (toast != null) {
            delay(4000)
            toast = null
        }
    }

    fun patch(id: Int, f: (OsAdjRow) -> OsAdjRow) {
        rows = rows.map { if (it.batchId == id) f(it) else it }
    }

    val changed = rows.filter { it.changed }
    val missingReason = changed.count { it.reason.isBlank() && remarks.isBlank() }
    val visible = if (changedOnly) changed else rows

    fun save() {
        reasonTouched = true
        if (changed.isNotEmpty() && missingReason > 0) {
            error = "Reason is required. Enter a reason before saving the adjustment."
            return
        }
        if (changed.isEmpty()) {
            error = "No changes to save. Adjust physical qty where it differs from system."
            return
        }
        val lines = JsonArray().apply {
            changed.forEach { r ->
                add(
                    JsonObject().apply {
                        addProperty("batch_id", r.batchId)
                        addProperty("system_qty", r.sysPacks)
                        addProperty("system_unit_qty", r.sysUnits)
                        addProperty("physical_qty", r.phyPacks)
                        addProperty("physical_unit_qty", r.phyUnits)
                        addProperty("reason", r.reason.ifBlank { remarks }.trim())
                    },
                )
            }
        }
        saving = true
        error = null
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.adjustmentSave(adjType, remarks.trim(), lines) } }
                    .onSuccess { data ->
                        val ref = data.str("adjustment_no")
                        val errs = data.arr("errors").mapNotNull { runCatching { it.asString }.getOrNull() }
                        if (errs.isNotEmpty()) {
                            error = "Saved $ref with warnings: ${errs.joinToString("; ")}"
                        } else {
                            toast = "Adjustment saved: $ref"
                            remarks = ""
                            reasonTouched = false
                        }
                        changedOnly = false
                        tick++
                    }
                    .onFailure { error = osErr(it, "Failed to save adjustment.") }
            } finally {
                saving = false
            }
        }
    }

    Column(Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.weight(1f).fillMaxWidth().padding(horizontal = CompactScreenPadding),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            item { Spacer(Modifier.height(2.dp)) }
            toast?.let { t -> item { PoToast(t) { toast = null } } }
            error?.let { e -> item { ErrorBanner(e) { error = null } } }
            item {
                PSection("Adjustment", expanded = true, onToggle = null, summary = "") {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
                        POptionDropdown(
                            "Adjustment type",
                            adjType,
                            OsAdjTypes.map { it.first },
                            { adjType = it },
                            Modifier.weight(1f),
                            display = { v -> OsAdjTypes.firstOrNull { it.first == v }?.second ?: v },
                        )
                        if (canAudit) {
                            OutlinedButton(
                                onClick = onAudit,
                                shape = RoundedCornerShape(6.dp),
                                contentPadding = PaddingValues(horizontal = 10.dp),
                                modifier = Modifier.height(SalesDenseFieldHeight + 2.dp),
                            ) { Text("Audit Log", style = MaterialTheme.typography.labelMedium) }
                        }
                    }
                    PLabel("Search product / batch")
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        SalesDenseSearchField(
                            value = search,
                            onValueChange = { search = it },
                            placeholder = "Product or batch",
                            modifier = Modifier.weight(1f),
                            leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                            keyboardActions = KeyboardActions(onSearch = { tick++ }),
                        )
                        PharmaOutlinedButton("Search", modifier = Modifier.heightIn(max = 36.dp)) { tick++ }
                        IconButton(onClick = { tick++ }, enabled = !loading, modifier = Modifier.size(SalesDenseFieldHeight)) {
                            Icon(Icons.Default.Refresh, "Refresh", Modifier.size(18.dp), tint = PharmaSlate)
                        }
                    }
                    val reasonError = reasonTouched && missingReason > 0
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        PLabel("Reason * — applies to every adjusted product unless a row has its own", isError = reasonError)
                    }
                    OsTextArea(
                        value = remarks,
                        onValue = { remarks = it.take(OS_REASON_MAX) },
                        placeholder = "Why is stock being adjusted? e.g. Physical count — 2 strips found damaged in rack B3",
                        isError = reasonError,
                    )
                    Row(Modifier.fillMaxWidth()) {
                        if (reasonError) {
                            Text(
                                "Reason is required — $missingReason adjusted product${if (missingReason == 1) "" else "s"} without a reason.",
                                Modifier.weight(1f),
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.error,
                            )
                        } else Spacer(Modifier.weight(1f))
                        Text("${remarks.length}/$OS_REASON_MAX", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    }
                    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text("Quick pick:", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        OsQuickReasons.forEach { q ->
                            PoChip(q, remarks == q) {
                                remarks = q
                                reasonTouched = true
                            }
                        }
                    }
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        if (loading) "Loading stock…" else "${rows.size} batch(es) · ${changed.size} changed",
                        Modifier.weight(1f),
                        style = MaterialTheme.typography.labelMedium,
                        color = PharmaSlate,
                        fontWeight = FontWeight.SemiBold,
                    )
                    PoChip("Changed only", changedOnly) { changedOnly = !changedOnly }
                }
            }
            when {
                loading && rows.isEmpty() -> item {
                    Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp)
                    }
                }
                visible.isEmpty() -> item {
                    PharmaCard {
                        Text(
                            if (rows.isEmpty()) "No stock found. Add opening stock or purchase first." else "No changed rows yet.",
                            Modifier.fillMaxWidth().padding(14.dp),
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                            textAlign = TextAlign.Center,
                        )
                    }
                }
                else -> items(visible, key = { it.batchId }) { r ->
                    OsAdjCard(
                        r = r,
                        enabled = canEdit,
                        commonReason = remarks.isNotBlank(),
                        showMissing = reasonTouched,
                        onUnits = { v ->
                            val units = max(0.0, floor(v))
                            patch(r.batchId) { it.copy(phyUnits = units, phyPacks = floor(units / it.upp)) }
                        },
                        onPacks = { v ->
                            val packs = max(0.0, floor(v))
                            patch(r.batchId) { it.copy(phyPacks = packs, phyUnits = packs * it.upp) }
                        },
                        onReason = { v -> patch(r.batchId) { it.copy(reason = v.take(OS_REASON_MAX)) } },
                        onReset = { patch(r.batchId) { it.copy(phyUnits = it.sysUnits, phyPacks = it.sysPacks, reason = "") } },
                    )
                }
            }
            item { Spacer(Modifier.height(12.dp)) }
        }
        if (canEdit) {
            PoBottomBar {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Column(Modifier.weight(1f)) {
                        Text("${changed.size} product(s) adjusted", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        val plus = changed.filter { it.unitDiff > 0 }.sumOf { it.unitDiff }
                        val minus = changed.filter { it.unitDiff < 0 }.sumOf { it.unitDiff }
                        Text(
                            "Add ${osNum(plus)} · Deduct ${osNum(-minus)} units" + if (missingReason > 0) " · $missingReason without reason" else "",
                            style = MaterialTheme.typography.labelSmall,
                            color = if (missingReason > 0 && reasonTouched) MaterialTheme.colorScheme.error else PharmaMuted,
                        )
                    }
                    PharmaPrimaryButton(
                        if (saving) "Saving…" else "Save Adjustment" + if (changed.isNotEmpty()) " (${changed.size})" else "",
                        enabled = !saving && changed.isNotEmpty(),
                    ) { save() }
                }
            }
        }
    }
}

@Composable
private fun OsTextArea(value: String, onValue: (String) -> Unit, placeholder: String, isError: Boolean) {
    BasicTextField(
        value = value,
        onValueChange = onValue,
        textStyle = MaterialTheme.typography.bodySmall.copy(color = PharmaSlate),
        cursorBrush = SolidColor(PharmaTeal),
        minLines = 2,
        maxLines = 4,
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .background(Color.White)
            .border(1.dp, if (isError) MaterialTheme.colorScheme.error else PFieldBorder, RoundedCornerShape(6.dp))
            .padding(8.dp),
        decorationBox = { inner ->
            Box {
                if (value.isEmpty()) Text(placeholder, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                inner()
            }
        },
    )
}

@Composable
private fun OsAdjCard(
    r: OsAdjRow,
    enabled: Boolean,
    commonReason: Boolean,
    showMissing: Boolean,
    onUnits: (Double) -> Unit,
    onPacks: (Double) -> Unit,
    onReason: (String) -> Unit,
    onReset: () -> Unit,
) {
    val missing = showMissing && r.changed && r.reason.isBlank() && !commonReason
    val expLabel = runCatching { LocalDate.parse(r.expiry.take(10)).let { "%02d/%d".format(it.monthValue, it.year) } }.getOrDefault("—")
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = if (r.changed) OsWarnBg else Color.White,
        border = BorderStroke(1.dp, if (r.changed) Color(0xFFF59E0B) else PharmaBorder),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(r.name, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Text(
                        listOf(r.mfr, "Batch ${r.batch.ifBlank { "—" }}", r.pack, "Exp $expLabel", if (r.shelf.isNotBlank()) "Shelf ${r.shelf}" else "")
                            .filter { it.isNotBlank() }.joinToString(" · "),
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
                if (r.changed && enabled) {
                    IconButton(onClick = onReset, modifier = Modifier.size(30.dp)) {
                        Icon(Icons.Default.RestartAlt, "Reset row", Modifier.size(17.dp), tint = PharmaMuted)
                    }
                }
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
                OsQtyBlock("System", osNum(r.sysUnits), osNum(r.sysPacks), Modifier.weight(1f))
                Column(Modifier.weight(1.3f)) {
                    PLabel("Physical units / packs")
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        if (enabled) {
                            PCellNum(r.phyUnits, onUnits, 64.dp, isInt = true)
                            PCellNum(r.phyPacks, onPacks, 56.dp, isInt = true)
                        } else {
                            Text("${osNum(r.phyUnits)} / ${osNum(r.phyPacks)}", style = MaterialTheme.typography.labelMedium)
                        }
                    }
                }
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.End) {
                    PLabel("Diff units / packs")
                    val color = when {
                        r.unitDiff > 0 -> OsOkGreen
                        r.unitDiff < 0 -> MaterialTheme.colorScheme.error
                        else -> PharmaMuted
                    }
                    Text(
                        "${osSigned(r.unitDiff)} / ${osSigned(r.packDiff)}",
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                        color = color,
                    )
                }
            }
            if (r.changed || r.reason.isNotBlank()) {
                SalesDenseSearchField(
                    value = r.reason,
                    onValueChange = onReason,
                    placeholder = when {
                        commonReason -> "Same as main reason"
                        r.changed -> "Reason required"
                        else -> "Own reason (optional)"
                    },
                    modifier = Modifier.fillMaxWidth(),
                    isError = missing,
                )
            }
        }
    }
}

@Composable
private fun OsQtyBlock(label: String, units: String, packs: String, modifier: Modifier) {
    Column(modifier) {
        PLabel("$label units / packs")
        Text("$units / $packs", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
    }
}

// ───────────────────────── Minimum inventory tab ─────────────────────────

@Composable
private fun OsMinInventoryTab(repo: StockRepository, canEdit: Boolean) {
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    val minFocus = remember { FocusRequester() }
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var resultsOpen by remember { mutableStateOf(false) }
    var searching by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<JsonObject?>(null) }
    var minQty by remember { mutableDoubleStateOf(0.0) }
    var triggers by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var saving by remember { mutableStateOf(false) }
    var removingId by remember { mutableIntStateOf(0) }
    var confirmReset by remember { mutableStateOf<JsonObject?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var tick by remember { mutableIntStateOf(0) }
    var searchTick by remember { mutableIntStateOf(0) }

    LaunchedEffect(tick) {
        loading = true
        runCatching { withContext(Dispatchers.IO) { repo.minTriggers() } }
            .onSuccess { triggers = it }
            .onFailure {
                triggers = emptyList()
                error = osErr(it, "Could not load minimum inventory triggers.")
            }
        loading = false
    }
    LaunchedEffect(query, searchTick) {
        if (selected != null || !resultsOpen) return@LaunchedEffect
        searching = true
        delay(300)
        runCatching { withContext(Dispatchers.IO) { repo.minProducts(query.trim()) } }
            .onSuccess { results = it }
            .onFailure { results = emptyList() }
        searching = false
    }
    LaunchedEffect(toast) {
        if (toast != null) {
            delay(4000)
            toast = null
        }
    }

    fun pick(row: JsonObject) {
        selected = row
        query = row.str("product_name")
        minQty = row.amount("min_qty").takeIf { it > 0 } ?: OS_DEFAULT_MIN.toDouble()
        resultsOpen = false
        error = null
        scope.launch {
            delay(60)
            runCatching { minFocus.requestFocus() }
        }
    }

    fun clear() {
        selected = null
        query = ""
        results = emptyList()
        minQty = 0.0
        resultsOpen = false
    }

    fun save() {
        val row = selected ?: run { error = "Please select a product."; return }
        if (minQty < 1) { error = "Minimum qty must be at least 1."; return }
        saving = true
        error = null
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.minSave(row.jInt("id"), minQty.toInt()) } }
                    .onSuccess { res ->
                        toast = res.str("message").ifBlank { "Minimum inventory trigger saved." }
                        clear()
                        tick++
                    }
                    .onFailure { error = osErr(it, "Save failed. Please try again.") }
            } finally {
                saving = false
            }
        }
    }

    fun reset(row: JsonObject) {
        val id = row.jInt("id")
        removingId = id
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.minRemove(id) } }
                    .onSuccess { res ->
                        toast = res.str("message").ifBlank { "Minimum reset to default." }
                        tick++
                    }
                    .onFailure { error = osErr(it, "Delete failed.") }
            } finally {
                removingId = 0
            }
        }
    }

    fun packHint(units: Double, upp: Int): String {
        if (upp <= 1) return ""
        val packs = floor(units / upp).toLong()
        val loose = (units - packs * upp).toLong()
        return if (loose != 0L) "$packs pack + $loose" else "$packs pack"
    }

    val lowCount = triggers.count { it.bool("is_low") }

    LazyColumn(
        Modifier.fillMaxSize().padding(horizontal = CompactScreenPadding),
        state = listState,
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        item { Spacer(Modifier.height(2.dp)) }
        toast?.let { t -> item { PoToast(t) { toast = null } } }
        error?.let { e -> item { ErrorBanner(e) { error = null } } }
        if (canEdit) {
            item {
                PSection("Set minimum", expanded = true, onToggle = null, summary = "") {
                    PLabel("Select product (from stock) *")
                    SalesDenseSearchField(
                        value = query,
                        onValueChange = {
                            query = it
                            selected = null
                            resultsOpen = true
                        },
                        placeholder = "Type to search product…",
                        modifier = Modifier.fillMaxWidth(),
                        leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                        trailingIcon = {
                            when {
                                searching -> CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                                query.isNotEmpty() -> IconButton(onClick = { clear() }, modifier = Modifier.size(22.dp)) {
                                    Icon(Icons.Default.Close, "Clear", Modifier.size(14.dp), tint = PharmaMuted)
                                }
                            }
                        },
                        onFocusChanged = { fs ->
                            if (fs.isFocused && selected == null && !resultsOpen) {
                                resultsOpen = true
                                if (results.isEmpty()) searchTick++
                            }
                        },
                    )
                    if (resultsOpen && selected == null && results.isNotEmpty()) {
                        Column(
                            Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(6.dp))
                                .border(1.dp, PFieldBorder, RoundedCornerShape(6.dp))
                                .heightIn(max = 280.dp)
                                .verticalScroll(rememberScrollState()),
                        ) {
                            results.forEach { r ->
                                Column(Modifier.fillMaxWidth().clickable { pick(r) }.padding(horizontal = 8.dp, vertical = 6.dp)) {
                                    Text(r.str("product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(
                                        "${r.str("pack_size").ifBlank { "—" }} · Qty: ${osNum(r.amount("available_qty"))} · Min: ${osNum(r.amount("min_qty"))}${if (r.bool("is_custom")) "" else " (default)"}",
                                        style = MaterialTheme.typography.labelSmall,
                                        color = PharmaMuted,
                                    )
                                }
                                HorizontalDivider(color = PharmaBorder.copy(alpha = 0.4f))
                            }
                        }
                    } else if (resultsOpen && selected == null && !searching && query.isNotBlank()) {
                        Text("No stock product matches “${query.trim()}”.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    }
                    val s = selected
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
                        val hint = s?.let { packHint(it.amount("available_qty"), it.jInt("units_per_pack")) }.orEmpty()
                        PReadField(
                            "Available qty" + if (hint.isNotEmpty()) " ($hint)" else "",
                            s?.let { osNum(it.amount("available_qty")) } ?: "—",
                            Modifier.weight(1f),
                        )
                        Column(Modifier.weight(1f)) {
                            PLabel("Minimum qty *" + (s?.let { if (it.bool("is_custom")) " · Custom" else " · Default" } ?: ""))
                            Spacer(Modifier.height(1.dp))
                            var text by remember(s) { mutableStateOf(if (minQty > 0) minQty.toLong().toString() else "") }
                            SalesDenseSearchField(
                                value = text,
                                onValueChange = { raw ->
                                    text = raw.filter { it.isDigit() }.take(7)
                                    minQty = text.toDoubleOrNull() ?: 0.0
                                },
                                placeholder = "Min qty",
                                modifier = Modifier.fillMaxWidth().focusRequester(minFocus),
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Done),
                                keyboardActions = KeyboardActions(onDone = { save() }),
                            )
                        }
                    }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PharmaPrimaryButton(if (saving) "Saving…" else "Save Trigger", modifier = Modifier.weight(1f), enabled = !saving) { save() }
                        PharmaOutlinedButton("Clear", modifier = Modifier.weight(1f)) { clear() }
                    }
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(6.dp)).background(Color(0xFFE0F2FE)).padding(8.dp),
                        verticalAlignment = Alignment.Top,
                    ) {
                        Icon(Icons.Default.Info, null, Modifier.size(15.dp), tint = Color(0xFF0369A1))
                        Spacer(Modifier.width(6.dp))
                        Text(
                            "Every product uses a default minimum of $OS_DEFAULT_MIN units. Set a custom minimum here for any product. " +
                                "When stock goes below its minimum, the product shows in the Dashboard alert and in Purchase Order → Auto-Purchase. " +
                                "Quantities are in units (tablets / pieces), same as stock.",
                            style = MaterialTheme.typography.labelSmall,
                            color = Color(0xFF075985),
                        )
                    }
                }
            }
        }
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                SalesLogKpiChip("Custom minimums", if (loading) "—" else triggers.size.toString(), Modifier.weight(1f))
                SalesLogKpiChip("Low stock", if (loading) "—" else lowCount.toString(), Modifier.weight(1f))
                SalesLogKpiChip("Default min", OS_DEFAULT_MIN.toString(), Modifier.weight(1f))
            }
        }
        item {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("CUSTOM MINIMUMS", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
                Spacer(Modifier.weight(1f))
                Text("Others use the default ($OS_DEFAULT_MIN)", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
        }
        when {
            loading && triggers.isEmpty() -> item {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp)
                }
            }
            triggers.isEmpty() -> item {
                PharmaCard {
                    Text(
                        "No custom minimums yet — all products use the default ($OS_DEFAULT_MIN).",
                        Modifier.fillMaxWidth().padding(14.dp),
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                        textAlign = TextAlign.Center,
                    )
                }
            }
            else -> itemsIndexed(triggers, key = { _, t -> t.jInt("id") }) { i, t ->
                val low = t.bool("is_low")
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = if (low) Color(0xFFFEF2F2) else Color.White,
                    border = BorderStroke(1.dp, if (low) MaterialTheme.colorScheme.error.copy(alpha = 0.4f) else PharmaBorder),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Row(Modifier.padding(start = 8.dp, top = 6.dp, bottom = 6.dp, end = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text("${i + 1}", Modifier.width(22.dp), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        Column(Modifier.weight(1f)) {
                            Text(t.str("product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(
                                    "${t.str("pack_size").ifBlank { "—" }} · Avail ",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaMuted,
                                )
                                Text(
                                    osNum(t.amount("available_qty")),
                                    style = MaterialTheme.typography.labelSmall,
                                    fontWeight = FontWeight.SemiBold,
                                    color = if (low) MaterialTheme.colorScheme.error else PharmaSlate,
                                )
                                Text(" · Min ${osNum(t.amount("min_qty"))}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            }
                        }
                        Text(
                            if (low) "Low Stock" else "OK",
                            Modifier
                                .clip(RoundedCornerShape(10.dp))
                                .background(if (low) Color(0xFFFEE2E2) else Color(0xFFDCFCE7))
                                .padding(horizontal = 7.dp, vertical = 1.dp),
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = if (low) Color(0xFFB91C1C) else Color(0xFF15803D),
                        )
                        if (canEdit) {
                            SalesLogIconAction(onClick = {
                                pick(t)
                                scope.launch { listState.animateScrollToItem(0) }
                            }, contentDescription = "Edit minimum") {
                                Icon(Icons.Default.Edit, null, Modifier.size(17.dp), tint = Color(0xFF0284C7))
                            }
                            SalesLogIconAction(onClick = { confirmReset = t }, contentDescription = "Reset to default") {
                                if (removingId == t.jInt("id")) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                                else Icon(Icons.Default.RestartAlt, null, Modifier.size(17.dp), tint = PharmaMuted)
                            }
                        }
                    }
                }
            }
        }
        item { Spacer(Modifier.height(12.dp)) }
    }

    confirmReset?.let { t ->
        AlertDialog(
            onDismissRequest = { confirmReset = null },
            title = { Text("Reset to default?", style = MaterialTheme.typography.titleSmall) },
            text = { Text("Reset ${t.str("product_name")} to the default minimum ($OS_DEFAULT_MIN)?", style = MaterialTheme.typography.bodySmall) },
            confirmButton = {
                TextButton(onClick = {
                    confirmReset = null
                    reset(t)
                }) { Text("Reset") }
            },
            dismissButton = { TextButton(onClick = { confirmReset = null }) { Text("Cancel") } },
        )
    }
}
