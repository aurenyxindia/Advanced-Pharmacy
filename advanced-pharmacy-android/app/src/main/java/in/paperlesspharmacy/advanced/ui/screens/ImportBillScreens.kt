package `in`.paperlesspharmacy.advanced.ui.screens

import android.content.Intent
import android.net.Uri
import android.util.Base64
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.ListAlt
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.AutoAwesome
import androidx.compose.material.icons.filled.Calculate
import androidx.compose.material.icons.filled.CameraAlt
import androidx.compose.material.icons.filled.Cancel
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.CloudUpload
import androidx.compose.material.icons.filled.DocumentScanner
import androidx.compose.material.icons.filled.Email
import androidx.compose.material.icons.filled.FileDownload
import androidx.compose.material.icons.filled.FolderOpen
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Save
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.TableChart
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.DisposableEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.transaction.IMPORT_MAX_UPLOAD_MB
import `in`.paperlesspharmacy.advanced.data.transaction.RetailCsvRow
import `in`.paperlesspharmacy.advanced.data.transaction.RetailPurchaseCsv
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.ImportLine
import `in`.paperlesspharmacy.advanced.ui.components.*
import androidx.lifecycle.viewModelScope
import `in`.paperlesspharmacy.advanced.ui.theme.CompactScreenPadding
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaBorder
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaMuted
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaSlate
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTeal
import `in`.paperlesspharmacy.advanced.ui.util.formatAmount2
import `in`.paperlesspharmacy.advanced.util.ContentUriReader
import `in`.paperlesspharmacy.advanced.util.ImportFileTools
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import java.io.File
import java.time.LocalDate
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.round
import kotlin.math.roundToInt

// ───────────────────────── Model & calculations (web import-hub / upload-purchase parity) ─────────────────────────

private enum class ImportPanel(val label: String, val icon: ImageVector) {
    Upload("Upload PO", Icons.Default.CloudUpload),
    Scan("Scan & Bill", Icons.Default.DocumentScanner),
    Ai("Import by AI", Icons.Default.AutoAwesome),
    Csv("CSV / Excel", Icons.Default.TableChart),
    Email("From Email", Icons.Default.Email),
}

private enum class LineFilter { All, Matched, Unmatched }

private data class ImportReport(
    val filename: String,
    val format: String,
    val requested: String,
    val aiFallback: Boolean = false,
    val aiError: String? = null,
    val source: ImportPanel,
)

private val ProductTypes = listOf("Medicine", "General", "Food/Nutra", "Cosmetics", "Patanjali")
private val MarginCategories = listOf("Allopathy", "Ayurvedic", "Surgical", "General", "Nutraceutical", "Cosmetic")
private val DosageForms = listOf("TABLET", "CAPSULE", "SYRUP", "INJECTION", "OINTMENT", "DROPS", "POWDER", "CREAM", "GEL", "SUSPENSION")
private val UnmatchedBg = Color(0xFFFFFBEB)
private val OkGreen = Color(0xFF16A34A)

private fun iR2(v: Double): Double = round((v + 1e-9) * 100.0) / 100.0
private fun iNorm(s: String): String = s.lowercase().replace(Regex("\\s+"), " ").trim()
private fun iYm(raw: String): String = RetailPurchaseCsv.expiryToYm(raw).takeIf { Regex("^\\d{4}-\\d{2}$").matches(it) }.orEmpty()

private fun iResolvePack(packs: List<JsonObject>, raw: String): String {
    val cur = raw.trim()
    if (cur.isEmpty()) return ""
    return pFindPack(packs, cur)?.str("pack")?.trim()?.ifBlank { null } ?: cur
}

private fun iUnits(packs: List<JsonObject>, pack: String, fallback: Int): Int {
    pFindPack(packs, pack)?.jInt("units")?.takeIf { it > 0 }?.let { return it }
    Regex("^(\\d+)x(\\d+)").find(pPackKey(pack))?.let { return max(1, it.groupValues[2].toIntOrNull() ?: 1) }
    return if (fallback > 1) fallback else pUnitsFromPack(pack)
}

/**
 * Retail upload-purchase calculateTaxable():
 * gross = qty × rate · disc = gross × disc% · cd = (gross − disc) × CD% · taxable = gross − disc − cd · net = taxable + GST.
 */
private fun iRecalc(l: ImportLine, cdPct: Double): ImportLine {
    val grossQr = iR2(l.qty * l.rate)
    val gross = if (l.srcGross > 0 && grossQr > 0 && abs(l.srcGross - grossQr) <= max(0.05, l.srcGross * 0.01)) iR2(l.srcGross) else grossQr
    var discPct = l.discPct
    if (discPct <= 0 && l.srcDisc > 0 && gross > 0) discPct = iR2(l.srcDisc * 100 / gross)
    var discAmt = iR2(gross * discPct / 100)
    if (l.srcDisc > 0 && abs(discAmt - l.srcDisc) <= max(0.05, l.srcDisc * 0.02)) {
        discAmt = iR2(l.srcDisc)
        if (gross > 0) discPct = iR2(discAmt * 100 / gross)
    }
    val cdAmt = if (cdPct > 0) iR2((gross - discAmt) * cdPct / 100) else 0.0
    val taxable = iR2(max(0.0, gross - discAmt - cdAmt))
    val gstAmt = if (l.splitGst) iR2(2 * iR2(taxable * l.gst / 200)) else iR2(taxable * l.gst / 100)
    return l.copy(gross = gross, discPct = discPct, discAmt = discAmt, cdAmt = cdAmt, taxable = taxable, gstAmt = gstAmt, net = iR2(taxable + gstAmt))
}

/** Server draft line (parse / AI / Gmail) → editable line (web normalizeLine). */
private fun iFromDraft(l: JsonObject, packs: List<JsonObject>, cdPct: Double, keepMatch: Boolean, defaultGst: Double = 0.0): ImportLine {
    val rawPack = l.str("pack_size").ifBlank { l.str("pack") }.ifBlank { l.str("pack_raw") }
    val pack = iResolvePack(packs, rawPack)
    val mid = if (keepMatch) l.jInt("master_product_id").takeIf { it > 0 } else null
    val base = ImportLine(
        product = l.str("product_name").uppercase(),
        matchedName = if (mid != null) l.str("matched_name").ifBlank { l.str("product_name") }.uppercase() else "",
        masterProductId = mid,
        hsn = l.str("hsn_code"),
        batch = l.str("batch_no").uppercase(),
        expiry = iYm(l.str("expiry_date")),
        pack = pack,
        packRaw = l.str("pack_raw").ifBlank { rawPack },
        upp = iUnits(packs, pack, l.jInt("units_per_pack").coerceAtLeast(1)),
        mfg = l.str("mfg_name").ifBlank { l.str("manufacturer_short") }.uppercase(),
        qty = l.amount("quantity").roundToInt(),
        free = l.amount("free_quantity").roundToInt(),
        rate = l.amount("purchase_rate"),
        mrp = l.amount("mrp"),
        ptr = l.amount("ptr"),
        pts = l.amount("pts"),
        discPct = l.amount("discount_pct"),
        discAmt = l.amount("discount_amt"),
        gst = l.amount("gst_rate").takeIf { it > 0 } ?: defaultGst,
        srcGross = l.amount("gross_amount"),
        srcDisc = l.amount("discount_amt"),
    )
    val net = l.amount("net_amount")
    val taxable = l.amount("taxable_amount")
    val gross = l.amount("gross_amount")
    // Trust server-reconciled bill footer amounts when CD% is 0 (web parity).
    if (cdPct <= 0 && (net > 0 || taxable > 0) && gross > 0) {
        return base.copy(gross = gross, cdAmt = 0.0, taxable = taxable, gstAmt = l.amount("gst_amount"), net = net)
    }
    return iRecalc(base, cdPct)
}

/**
 * Retail Upload PO row → line. Gross is always qty × rate; disc% comes from the CSV disc amount
 * when the file has no % (Retail calculateAll), then disc ₹ is recomputed from that %.
 */
private fun iFromRetail(r: RetailCsvRow, packs: List<JsonObject>, cdPct: Double): ImportLine {
    val pack = iResolvePack(packs, r.pack)
    val qty = r.qty.roundToInt()
    val base = qty * r.rate
    val discPct = if (r.discPct <= 0 && r.discAmt > 0 && base > 0) iR2(100 * r.discAmt / base) else r.discPct
    return iRecalc(
        ImportLine(
            product = r.product.uppercase(),
            hsn = r.hsn,
            batch = r.batch.uppercase(),
            expiry = iYm(r.expiry),
            pack = pack,
            packRaw = r.pack,
            upp = iUnits(packs, pack, 1),
            mfg = r.mfg.uppercase(),
            qty = qty,
            free = r.free.roundToInt(),
            rate = r.rate,
            mrp = r.mrp,
            ptr = r.ptr,
            pts = r.pts,
            discPct = discPct,
            gst = r.gst,
            splitGst = true,
        ),
        cdPct,
    )
}

private fun iApplyHit(l: ImportLine, hit: JsonObject, packs: List<JsonObject>, cdPct: Double, candidates: List<JsonObject> = l.matches): ImportLine {
    val hitPack = hit.str("pack_size")
    val pack = if (hitPack.isNotBlank()) iResolvePack(packs, hitPack) else l.pack.ifBlank { iResolvePack(packs, l.packRaw) }
    val hitGst = hit.amount("default_gst_rate")
    return iRecalc(
        l.copy(
            masterProductId = hit.jInt("id"),
            matchedName = hit.str("name").uppercase(),
            matches = candidates,
            gst = if (l.gst <= 0 && hitGst > 0) hitGst.roundToInt().toDouble() else l.gst,
            hsn = l.hsn.ifBlank { hit.str("hsn_code") },
            mfg = if (l.splitGst) hit.str("manufacturer").uppercase().ifBlank { l.mfg }
            else l.mfg.ifBlank { hit.str("manufacturer").uppercase() },
            pack = pack,
            upp = iUnits(packs, pack, l.upp),
        ),
        cdPct,
    )
}

private fun iValidate(l: ImportLine): String? = when {
    l.masterProductId == null -> "Match or Add product first."
    l.pack.isBlank() -> "Pack size is required."
    l.batch.isBlank() -> "Batch number is required."
    l.expiry.isBlank() -> "Expiry date is required."
    l.qty <= 0 -> "Quantity must be greater than 0."
    l.mrp <= 0 -> "MRP must be greater than 0."
    l.rate <= 0 -> "Purchase rate must be greater than 0."
    else -> null
}

private fun csvCell(v: Any?): String {
    val s = v?.toString().orEmpty()
    return if (s.contains(',') || s.contains('"') || s.contains('\n')) "\"" + s.replace("\"", "\"\"") + "\"" else s
}

// ───────────────────────── Screen ─────────────────────────

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ImportBillHubScreen(
    vm: AppViewModel,
    onBack: () -> Unit,
    onSaved: () -> Unit = onBack,
    onNewPurchase: () -> Unit = {},
) {
    val importRepo = remember { vm.imports() }
    val purchaseRepo = remember { vm.purchases() }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val config = LocalConfiguration.current
    val canEdit = vm.hasFullAccess() || vm.canEdit("IMPORT_BILL")
    val today = remember { LocalDate.now().toString() }
    val session = vm.importBillSession

    // Draft / AI extract state lives on ViewModel (survives rotation).
    var verify by session::verify
    var busy by session::busy
    var busyLabel by session::busyLabel
    var matching by session::matching
    var saving by session::saving
    var error by session::error
    var toast by session::toast
    var gmailAwaitingOauth by session::gmailAwaitingOauth
    var retailFormat by session::retailFormat
    var serverFormat by session::serverFormat
    var csvRawText by session::csvRawText
    var fileName by session::fileName
    var invoiceNo by session::invoiceNo
    var invoiceDate by session::invoiceDate
    var invoiceDup by session::invoiceDup
    var supplierId by session::supplierId
    var supplierName by session::supplierName
    var cashCredit by session::cashCredit
    var cdMode by session::cdMode
    var grossBillAmt by session::grossBillAmt
    var cdAmtInput by session::cdAmtInput
    var cdPct by session::cdPct
    var debitNoteNo by session::debitNoteNo
    var debitAmt by session::debitAmt
    var headerExpanded by session::headerExpanded
    var lines by session::lines

    val panel = ImportPanel.entries.find { it.name == session.panel } ?: ImportPanel.Upload
    fun setPanel(p: ImportPanel) { session.panel = p.name }
    val lineFilter = LineFilter.entries.find { it.name == session.lineFilter } ?: LineFilter.All
    fun setLineFilter(f: LineFilter) { session.lineFilter = f.name }
    val report: ImportReport? = if (!session.hasReport()) null else ImportReport(
        filename = session.reportFilename,
        format = session.reportFormat,
        requested = session.reportRequested,
        aiFallback = session.reportAiFallback,
        aiError = session.reportAiError,
        source = ImportPanel.entries.find { it.name == session.reportSource } ?: panel,
    )
    fun setReport(value: ImportReport?) {
        if (value == null) {
            session.reportFilename = ""
            session.reportFormat = ""
            session.reportRequested = ""
            session.reportAiFallback = false
            session.reportAiError = null
            session.reportSource = session.panel
        } else {
            session.reportFilename = value.filename
            session.reportFormat = value.format
            session.reportRequested = value.requested
            session.reportAiFallback = value.aiFallback
            session.reportAiError = value.aiError
            session.reportSource = value.source.name
        }
    }

    // Master data (reloadable)
    var packOptions by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var distributors by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var distributorsLoading by remember { mutableStateOf(true) }
    var serverFormats by remember { mutableStateOf(listOf("Auto")) }
    var serverFormatLabels by remember { mutableStateOf(mapOf("Auto" to "Auto-detect")) }
    var manufacturers by remember { mutableStateOf<List<String>>(emptyList()) }
    var aiConfigured by remember { mutableStateOf(true) }
    var aiMessage by remember { mutableStateOf<String?>(null) }
    var gmailConfigured by remember { mutableStateOf(true) }
    var gmailLinked by remember { mutableStateOf(false) }
    var gmailEmail by remember { mutableStateOf<String?>(null) }
    var gmailMessage by remember { mutableStateOf<String?>(null) }
    var attachments by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var supplierSheet by remember { mutableStateOf(false) }
    var quickMaster by remember { mutableStateOf<String?>(null) }
    var quickName by remember { mutableStateOf("") }
    val canQuickAddMaster = vm.canEdit("MASTERS") || vm.hasFullAccess()
    var debitNotes by remember { mutableStateOf<List<JsonObject>>(emptyList()) }

    // Sheets / dialogs (ephemeral)
    var packSheetFor by remember { mutableStateOf<Long?>(null) }
    var matchSheetFor by remember { mutableStateOf<Long?>(null) }
    var pendingProductLineKey by remember { mutableStateOf<Long?>(null) }
    var showPtrCalc by remember { mutableStateOf(false) }
    val rateJobs = remember { mutableMapOf<Long, Job>() }
    var bulkRatesJob by remember { mutableStateOf<Job?>(null) }

    LaunchedEffect(Unit) {
        if (session.invoiceDate.isBlank()) session.invoiceDate = today
        // Unstick spinner / Saving left from a killed/orphaned job.
        session.recoverStuckBusy()
    }

    // Leaving Import Bill must cancel ViewModel extract so busy does not stick on return.
    DisposableEffect(Unit) {
        onDispose {
            // Only clear orphan flags if user left while no work should continue.
            // Active AI extract intentionally survives on viewModelScope; cancel on dispose
            // so shell Back does not leave a zombie spinner.
            session.cancelWork()
        }
    }

    // ── Loaders ──
    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { purchaseRepo.packSizes() } }.onSuccess { packOptions = it }
    }
    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { purchaseRepo.allDistributors() } }
            .onSuccess { list -> distributors = list.distinctBy { it.str("name").trim().uppercase() } }
        distributorsLoading = false
    }
    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { importRepo.formats() } }.onSuccess { list ->
            val keys = list.map { it.str("key").ifBlank { it.str("label") } }.filter { it.isNotBlank() }
            serverFormatLabels = mapOf("Auto" to "Auto-detect") + list.associate { f ->
                f.str("key").ifBlank { f.str("label") } to f.str("label").ifBlank { f.str("key") }
            }
            serverFormats = (listOf("Auto") + keys).distinct()
        }
    }
    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { importRepo.manufacturers() } }
            .onSuccess { manufacturers = it }
    }

    fun refreshAiStatus() {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { importRepo.aiStatus() } }
                .onSuccess { aiConfigured = it.bool("configured"); aiMessage = it.str("message").ifBlank { null } }
                .onFailure { aiConfigured = false; aiMessage = "Could not reach AI status endpoint." }
        }
    }

    fun loadAttachments() {
        session.resetWorkLocks()
        busy = true
        busyLabel = "Loading attachments…"
        val job = vm.viewModelScope.launch {
            val self = coroutineContext[kotlinx.coroutines.Job]
            try {
                runCatching { withContext(Dispatchers.IO) { importRepo.gmailList() } }
                    .onSuccess { attachments = it }
                    .onFailure { error = it.message ?: "Failed to list Gmail attachments." }
            } finally {
                if (session.workJob === self) {
                    session.workJob = null
                    busy = false
                }
            }
        }
        session.workJob = job
    }

    fun refreshGmailStatus(loadList: Boolean = false) {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { importRepo.gmailStatus() } }
                .onSuccess { s ->
                    gmailConfigured = s.bool("configured")
                    gmailLinked = s.bool("linked")
                    gmailEmail = s.str("email").ifBlank { null }
                    gmailMessage = s.str("message").ifBlank { null }
                    if (loadList && gmailLinked) loadAttachments()
                }
                .onFailure {
                    gmailConfigured = false
                    gmailLinked = false
                    gmailMessage = "Could not reach Gmail status endpoint."
                }
        }
    }

    LaunchedEffect(panel) {
        when (panel) {
            ImportPanel.Scan, ImportPanel.Ai -> refreshAiStatus()
            ImportPanel.Email -> refreshGmailStatus(loadList = true)
            else -> Unit
        }
    }

    // Back from the browser after Gmail OAuth → refresh link + attachments.
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner) {
        val obs = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && gmailAwaitingOauth) {
                gmailAwaitingOauth = false
                refreshGmailStatus(loadList = true)
            }
        }
        lifecycleOwner.lifecycle.addObserver(obs)
        onDispose { lifecycleOwner.lifecycle.removeObserver(obs) }
    }

    // Duplicate invoice check (debounced like web)
    LaunchedEffect(invoiceNo) {
        invoiceDup = null
        val inv = invoiceNo.trim()
        if (inv.isEmpty()) return@LaunchedEffect
        delay(400)
        runCatching { withContext(Dispatchers.IO) { importRepo.checkInvoice(inv) } }.onSuccess { r ->
            if (r.bool("exists")) {
                val p = r.obj("purchase")
                val po = p.str("po_no").ifBlank { p.str("id") }
                val dt = p.str("invoice_date").take(10)
                invoiceDup = "Invoice already used${if (po.isNotBlank()) " (PO $po${if (dt.isNotBlank()) ", ${formatSaleDateSafe(dt)}" else ""})" else ""}."
            }
        }
    }

    // ── Line helpers ──
    fun recalcAll(pct: Double = cdPct) {
        lines = lines.map { iRecalc(it, pct) }
    }

    fun fetchRates(key: Long) {
        val l = lines.firstOrNull { it.key == key } ?: return
        if (l.mrp <= 0) return
        rateJobs[key]?.cancel()
        rateJobs[key] = scope.launch {
            delay(300)
            runCatching { withContext(Dispatchers.IO) { purchaseRepo.computeRates(l.mrp, l.gst, "Allopathy") } }
                .onSuccess { r -> lines = lines.map { if (it.key == key) it.copy(ptr = r.amount("ptr"), pts = r.amount("pts")) else it } }
        }
    }

    /** One job for the whole bill: one call per distinct MRP+GST, then a single table update. */
    fun autoFillMissingPtrPts() {
        val pending = lines.filter { it.ptr <= 0 && it.mrp > 0 }
        if (pending.isEmpty()) return
        bulkRatesJob?.cancel()
        bulkRatesJob = scope.launch {
            val pairs = pending.map { it.mrp to it.gst }.distinct()
            val rates = withContext(Dispatchers.IO) {
                pairs.chunked(6).flatMap { chunk ->
                    chunk.map { p ->
                        async { p to runCatching { purchaseRepo.computeRates(p.first, p.second, "Allopathy") }.getOrNull() }
                    }.awaitAll()
                }.toMap()
            }
            lines = lines.map { l ->
                val r = if (l.ptr <= 0 && l.mrp > 0) rates[l.mrp to l.gst] else null
                if (r == null) l
                else l.copy(ptr = r.amount("ptr").takeIf { it > 0 } ?: l.ptr, pts = if (l.pts > 0) l.pts else r.amount("pts"))
            }
        }
    }

    fun updateLine(key: Long, change: (ImportLine) -> ImportLine) {
        lines = lines.map { if (it.key == key) iRecalc(change(it), cdPct) else it }
    }

    fun loadDebitNotes(party: String) {
        debitNoteNo = ""
        debitAmt = 0.0
        debitNotes = emptyList()
        if (party.isBlank()) return
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { purchaseRepo.openDebitNotes(party) } }.onSuccess { debitNotes = it }
        }
    }

    fun pickSupplier(s: JsonObject) {
        supplierId = s.jInt("id")
        supplierName = s.str("name").uppercase()
        supplierSheet = false
        loadDebitNotes(supplierName)
    }

    fun supplierFromHeader(name: String) {
        val n = name.trim()
        if (n.isEmpty()) return
        val found = distributors.firstOrNull { it.str("name").equals(n, true) }
            ?: distributors.firstOrNull { d ->
                val dn = d.str("name")
                dn.isNotBlank() && (dn.contains(n, true) || n.contains(dn, true))
            }
        if (found != null) pickSupplier(found) else if (supplierId <= 0) {
            supplierName = n.uppercase()
            loadDebitNotes(supplierName)
        }
    }

    fun recalcCdFromAmount() {
        if (cdMode != "Amount") return
        cdPct = if (grossBillAmt > 0) iR2(cdAmtInput / grossBillAmt * 100) else 0.0
        recalcAll()
    }

    fun matchAllWithMaster(silent: Boolean = false) {
        if (lines.isEmpty()) {
            error = "Load a bill first."
            return
        }
        // Clears busy from parent runBusy (Upload CSV → match) so the spinner cannot stick.
        session.resetWorkLocks()
        matching = true
        val job = vm.viewModelScope.launch {
            val self = coroutineContext[kotlinx.coroutines.Job]
            try {
                val snapshot = lines
                val packs = packOptions
                val pct = cdPct
                runCatching { withContext(Dispatchers.IO) { importRepo.matchByNames(snapshot.map { it.product }, 3) } }
                    .onSuccess { byName ->
                        val matchedByKey = withContext(Dispatchers.Default) {
                            snapshot.associate { l ->
                                val hits = byName[l.product.trim().lowercase()].orEmpty()
                                l.key to (if (hits.isNotEmpty()) iApplyHit(l, hits.first(), packs, pct, hits)
                                else l.copy(masterProductId = null, matchedName = "", matches = emptyList()))
                            }
                        }
                        lines = lines.map { matchedByKey[it.key] ?: it }
                        autoFillMissingPtrPts()
                        val ok = lines.count { it.masterProductId != null }
                        if (!silent) toast = "Matched $ok of ${lines.size} products."
                    }
                    .onFailure { error = "Product matching failed. Try again." }
            } finally {
                if (session.workJob === self) {
                    session.workJob = null
                    matching = false
                }
            }
        }
        session.workJob = job
    }

    fun enterVerify(newLines: List<ImportLine>, rep: ImportReport, header: JsonObject?, fillRates: Boolean = true) {
        lines = newLines
        setReport(rep)
        setLineFilter(LineFilter.All)
        header?.let { h ->
            h.str("invoice_no").takeIf { it.isNotBlank() }?.let { invoiceNo = it.uppercase() }
            h.str("invoice_date").take(10).takeIf { Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(it) }?.let { invoiceDate = it }
            h.str("supplier_name").takeIf { it.isNotBlank() }?.let { supplierFromHeader(it) }
        }
        verify = true
        headerExpanded = true
        if (fillRates) autoFillMissingPtrPts()
    }

    fun applyServerDraft(data: JsonObject, source: ImportPanel, requested: String, name: String) {
        val keepMatch = source != ImportPanel.Scan
        val arr = data.arr("lines")
        val newLines = (0 until arr.size()).mapNotNull { i ->
            arr.get(i).takeIf { it.isJsonObject }?.asJsonObject?.let {
                iFromDraft(it, packOptions, cdPct, keepMatch, defaultGst = if (source == ImportPanel.Scan) 5.0 else 0.0)
            }
        }
        if (newLines.isEmpty()) {
            error = "No purchase lines were found in this file."
            return
        }
        val fmt = data.str("format").ifBlank { if (source == ImportPanel.Scan || source == ImportPanel.Ai) "AI" else "Generic" }
        enterVerify(
            newLines,
            ImportReport(
                filename = data.str("filename").ifBlank { name },
                format = fmt,
                requested = data.str("format_requested").ifBlank { requested },
                aiFallback = data.bool("ai_fallback"),
                aiError = data.str("ai_error").ifBlank { null },
                source = source,
            ),
            data.obj("header"),
            // Scan/AI: skip bulk PTR/PTS burst on the main thread (user can Match / rates later).
            fillRates = source != ImportPanel.Scan && source != ImportPanel.Ai,
        )
        val matched = newLines.count { it.masterProductId != null }
        toast = when {
            source == ImportPanel.Scan -> "Extracted ${newLines.size} product(s). Tap “Match with master” when ready."
            data.bool("ai_fallback") -> "OpenAI was busy — loaded ${newLines.size} line(s) via $fmt ($matched matched)."
            else -> "Import report ready: ${newLines.size} line(s) · $matched matched · format $fmt."
        }
    }

    fun guardSize(bytes: ByteArray): Boolean {
        if (bytes.size > IMPORT_MAX_UPLOAD_MB * 1024 * 1024) {
            error = "File exceeds the maximum allowed size of $IMPORT_MAX_UPLOAD_MB MB."
            return false
        }
        return true
    }

    suspend fun loadRetailCsv(text: String, name: String) {
        val format = retailFormat
        val packs = packOptions
        val pct = cdPct
        val parsed = withContext(Dispatchers.Default) {
            runCatching { RetailPurchaseCsv.parse(text, format).map { iFromRetail(it, packs, pct) } }
        }
        val newLines = parsed.getOrElse {
            error = "Could not read this CSV as $format: ${it.message ?: "invalid file"}"
            return
        }
        if (newLines.isEmpty()) {
            error = "No product rows found for format $format. Check the format and file."
            return
        }
        csvRawText = text
        enterVerify(
            newLines,
            ImportReport(filename = name, format = format, requested = format, source = ImportPanel.Upload),
            null,
            fillRates = false,
        )
        matchAllWithMaster()
    }

    fun runBusy(label: String, block: suspend () -> Unit) {
        session.resetWorkLocks()
        busy = true
        busyLabel = label
        val job = vm.viewModelScope.launch {
            val self = coroutineContext[kotlinx.coroutines.Job]
            try {
                block()
            } catch (_: kotlinx.coroutines.CancellationException) {
                // cancelled
            } catch (e: Exception) {
                error = e.message ?: "Something went wrong."
            } finally {
                if (session.workJob === self) {
                    session.workJob = null
                    busy = false
                }
            }
        }
        session.workJob = job
    }

    // ── Pickers ──
    val uploadPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        error = null
        runBusy("Reading CSV…") {
            val (name, bytes) = withContext(Dispatchers.IO) {
                ContentUriReader.displayName(context, uri) to ContentUriReader.readBytes(context, uri)
            }
            if (guardSize(bytes)) {
                fileName = name
                busyLabel = "Building bill lines…"
                loadRetailCsv(String(bytes, Charsets.UTF_8), name)
            }
        }
    }

    val csvPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        error = null
        runBusy("Parsing…") {
            val (name, data) = withContext(Dispatchers.IO) {
                val name = ContentUriReader.displayName(context, uri)
                val bytes = ContentUriReader.readBytes(context, uri)
                if (bytes.size > IMPORT_MAX_UPLOAD_MB * 1024 * 1024) {
                    throw IllegalArgumentException("File exceeds the maximum allowed size of $IMPORT_MAX_UPLOAD_MB MB.")
                }
                val lower = name.lowercase()
                val data = when {
                    lower.endsWith(".xls") -> throw IllegalArgumentException("Legacy .xls is not supported. Save as .xlsx or .csv and try again.")
                    lower.endsWith(".xlsx") -> importRepo.parseCsvText(ImportFileTools.xlsxToCsv(bytes), serverFormat, name.replace(Regex("\\.xlsx$", RegexOption.IGNORE_CASE), ".csv"))
                    lower.endsWith(".csv") || lower.endsWith(".tsv") || lower.endsWith(".txt") -> importRepo.parseCsvFile(bytes, name, serverFormat)
                    else -> importRepo.parseCsvFile(bytes, "$name.csv", serverFormat)
                }
                name to data
            }
            fileName = name
            applyServerDraft(data, ImportPanel.Csv, serverFormat, name)
        }
    }

    fun processBill(uri: Uri, fallbackName: String, source: ImportPanel) {
        if (!aiConfigured) {
            error = aiMessage ?: "AI is not configured on the server."
            return
        }
        // Replace any stuck / previous extract instead of silently no-oping.
        session.resetWorkLocks()
        error = null
        setPanel(source)
        busy = true
        busyLabel = "Preparing bill…"
        val appCtx = context.applicationContext
        val uriStable = uri
        val job = vm.viewModelScope.launch {
            val self = coroutineContext[kotlinx.coroutines.Job]
            try {
                val (name, images) = withContext(Dispatchers.IO) {
                    val name = runCatching { ContentUriReader.displayName(appCtx, uriStable) }.getOrDefault(fallbackName)
                    val bytes = ContentUriReader.readBytes(appCtx, uriStable)
                    if (bytes.size > IMPORT_MAX_UPLOAD_MB * 1024 * 1024) {
                        throw IllegalArgumentException("File too large. Max $IMPORT_MAX_UPLOAD_MB MB.")
                    }
                    name to ImportFileTools.billToAiImages(appCtx, name, bytes)
                }
                session.fileName = name
                session.busyLabel = "AI extracting ${images.size} page(s) — large bills can take a few minutes…"
                val data = withContext(Dispatchers.IO) { importRepo.extractAi(images) }
                session.pendingAiDraft = data
                session.pendingAiSource = source.name
                session.pendingAiRequested = "AI"
                session.pendingAiName = name
            } catch (_: kotlinx.coroutines.CancellationException) {
                // Replaced or user cancelled — do not toast as failure.
            } catch (e: Exception) {
                session.error = e.message ?: "AI extraction failed."
            } finally {
                if (session.workJob === self) {
                    session.workJob = null
                    session.busy = false
                }
            }
        }
        session.workJob = job
    }

    // Apply AI result that finished while the screen was recreating / packs were loading.
    LaunchedEffect(session.pendingAiDraft, packOptions.size) {
        val draft = session.pendingAiDraft ?: return@LaunchedEffect
        // Do not wait forever if pack master failed to load — apply with empty packs.
        val src = ImportPanel.entries.find { it.name == session.pendingAiSource } ?: ImportPanel.Scan
        val req = session.pendingAiRequested ?: "AI"
        val name = session.pendingAiName ?: "bill"
        session.clearPendingAi()
        applyServerDraft(draft, src, req, name)
    }

    val billPicker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri: Uri? ->
        if (uri != null) processBill(uri, "bill", panel)
    }
    val cameraLauncher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok ->
        val u = session.cameraUri?.let { Uri.parse(it) }
        if (ok && u != null) processBill(u, "bill_photo.jpg", ImportPanel.Scan)
    }

    fun launchCamera() {
        runCatching {
            val f = File(context.cacheDir, "bill_photo_${System.currentTimeMillis()}.jpg")
            val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", f)
            session.cameraUri = uri.toString()
            cameraLauncher.launch(uri)
        }.onFailure { error = it.message ?: "Camera is not available." }
    }

    fun importAttachment(att: JsonObject) {
        if (att.amount("size") > IMPORT_MAX_UPLOAD_MB * 1024.0 * 1024.0) {
            error = "File exceeds the maximum allowed size of $IMPORT_MAX_UPLOAD_MB MB."
            return
        }
        error = null
        val name = att.str("filename")
        runBusy("Importing $name…") {
            val data = withContext(Dispatchers.IO) {
                val data = importRepo.gmailDownload(att, serverFormat)
                if (data.bool("needs_excel_convert") && data.str("base64").isNotBlank()) {
                    val bytes = Base64.decode(data.str("base64"), Base64.DEFAULT)
                    importRepo.parseCsvText(ImportFileTools.xlsxToCsv(bytes), serverFormat, name.substringBeforeLast('.') + ".csv")
                } else data
            }
            fileName = name
            applyServerDraft(data, ImportPanel.Email, serverFormat, name)
        }
    }

    fun connectGmail() {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { importRepo.gmailStartUrl() } }
                .onSuccess { url ->
                    if (url.isBlank()) {
                        error = "Could not start Gmail OAuth."
                    } else {
                        gmailAwaitingOauth = true
                        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
                            .onFailure { error = "No browser available to connect Gmail." }
                    }
                }
                .onFailure { error = it.message ?: "Could not start Gmail OAuth." }
        }
    }

    fun disconnectGmail() {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { importRepo.gmailDisconnect() } }
                .onSuccess {
                    gmailLinked = false
                    gmailEmail = null
                    attachments = emptyList()
                    toast = "Gmail disconnected."
                }
                .onFailure { error = it.message }
        }
    }

    fun resetToSource() {
        verify = false
        error = null
        setLineFilter(LineFilter.All)
    }

    fun clearBill() {
        session.clearBill()
    }

    // ── Totals & checks ──
    val tGross = iR2(lines.sumOf { it.gross })
    val tDisc = iR2(lines.sumOf { it.discAmt })
    val tCd = iR2(lines.sumOf { it.cdAmt })
    val tTaxable = iR2(lines.sumOf { it.taxable })
    val tGst = iR2(lines.sumOf { it.gstAmt })
    val billNet = iR2(lines.sumOf { it.net })
    val payable = iR2(max(0.0, billNet - debitAmt))
    val matchedCount = lines.count { it.masterProductId != null }
    val unmatchedCount = lines.size - matchedCount

    val chkSupplier = supplierId > 0 || supplierName.isNotBlank()
    val chkInvoice = invoiceNo.isNotBlank() && invoiceDate.isNotBlank()
    val chkNoDup = invoiceDup == null
    val chkLines = lines.isNotEmpty()
    val chkMatched = chkLines && unmatchedCount == 0
    val chkQty = lines.all { it.qty >= 1 }
    val chkRates = lines.all { it.rate >= 0 && it.mrp >= 0 }

    fun shareReport() {
        val rep = report
        val body = buildString {
            appendLine("Metric,Value")
            listOf(
                "File" to rep?.filename.orEmpty(),
                "Format requested" to rep?.requested.orEmpty(),
                "Format used" to rep?.format.orEmpty(),
                "Lines" to lines.size.toString(),
                "Matched" to matchedCount.toString(),
                "Unmatched" to unmatchedCount.toString(),
                "Invoice" to invoiceNo,
                "Invoice date" to invoiceDate,
                "Supplier" to supplierName,
                "Gross" to tGross.toString(),
                "Discount" to tDisc.toString(),
                "CD" to tCd.toString(),
                "Taxable" to tTaxable.toString(),
                "GST" to tGst.toString(),
                "Net" to billNet.toString(),
            ).forEach { (k, v) -> appendLine("${csvCell(k)},${csvCell(v)}") }
            appendLine()
            appendLine("#,Matched,Product,Batch,Qty,Rate,MRP,Gross,Net")
            lines.forEachIndexed { i, l ->
                appendLine(listOf(i + 1, if (l.masterProductId != null) "Y" else "N", l.matchedName.ifBlank { l.product }, l.batch, l.qty, l.rate, l.mrp, l.gross, l.net).joinToString(",") { csvCell(it) })
            }
        }
        val base = (rep?.filename ?: "import").substringBeforeLast('.')
        shareCsv(context, body, "${base}_import_report.csv", "Share import report")?.let { error = it }
    }

    fun shareUnmatched() {
        val rows = lines.filter { it.masterProductId == null }
        if (rows.isEmpty()) {
            toast = "All lines are matched — nothing to export."
            return
        }
        val body = buildString {
            appendLine("Product Name,Batch,Qty,Free,MRP,Rate,PTR,PTS,Pack,MFG,Expiry,GST %")
            rows.forEach { l ->
                appendLine(listOf(l.product, l.batch, l.qty, l.free, l.mrp, l.rate, l.ptr, l.pts, l.pack, l.mfg, l.expiry, l.gst).joinToString(",") { csvCell(it) })
            }
        }
        val base = (report?.filename ?: "import").substringBeforeLast('.')
        shareCsv(context, body, "${base}_unmatched.csv", "Share unmatched lines")?.let { error = it }
    }

    fun save() {
        if (saving || busy || matching) return
        val err = when {
            invoiceNo.isBlank() -> "Invoice No is required."
            invoiceDate.isBlank() -> "Invoice Date is required."
            !chkSupplier -> "Distributor is required."
            invoiceDup != null -> invoiceDup
            lines.isEmpty() -> "At least 1 product is required."
            else -> lines.withIndex().firstNotNullOfOrNull { (i, l) -> iValidate(l)?.let { "Row ${i + 1}: $it" } }
        }
        if (err != null) {
            error = err
            if (invoiceNo.isBlank() || !chkSupplier || invoiceDup != null) headerExpanded = true
            if (!chkMatched) setLineFilter(LineFilter.Unmatched)
            return
        }
        val items = JsonArray()
        lines.forEach { l ->
            items.add(JsonObject().apply {
                addProperty("master_product_id", l.masterProductId)
                addProperty("product_name", l.matchedName.ifBlank { l.product })
                if (l.hsn.isNotBlank()) addProperty("hsn_code", l.hsn)
                addProperty("batch_no", l.batch.trim().uppercase())
                addProperty("expiry_date", if (l.expiry.length == 7) "${l.expiry}-01" else l.expiry)
                addProperty("quantity", l.qty)
                addProperty("free_quantity", l.free)
                addProperty("purchase_rate", l.rate)
                addProperty("mrp", l.mrp)
                if (l.ptr > 0) addProperty("ptr", l.ptr)
                if (l.pts > 0) addProperty("pts", l.pts)
                addProperty("discount_pct", l.discPct)
                addProperty("gst_rate", l.gst)
                addProperty("pack_size", l.pack)
                addProperty("units_per_pack", iUnits(packOptions, l.pack, l.upp).coerceAtLeast(1))
                addProperty("pack_raw", l.packRaw)
            })
        }
        val body = JsonObject().apply {
            addProperty("invoice_no", invoiceNo.trim().uppercase())
            addProperty("invoice_date", invoiceDate)
            if (supplierId > 0) addProperty("supplier_id", supplierId)
            addProperty("supplier_name", supplierName.trim())
            addProperty("cash_credit", cashCredit)
            addProperty("cd_mode", cdMode)
            addProperty("cd_pct", cdPct)
            if (cdMode == "Amount") addProperty("cd_amount", cdAmtInput)
            if (debitNoteNo.isNotBlank()) addProperty("debit_note_no", debitNoteNo)
            addProperty("debit_deduction_amt", debitAmt)
            addProperty("bill_discount_pct", 0)
            add("items", items)
        }
        session.resetWorkLocks()
        saving = true
        error = null
        val job = vm.viewModelScope.launch {
            val self = coroutineContext[kotlinx.coroutines.Job]
            try {
                runCatching { withContext(Dispatchers.IO) { purchaseRepo.create(body) } }
                    .onSuccess { r ->
                        val net = r.obj("data").amount("net_amount").takeIf { it > 0 } ?: payable
                        toast = "Purchase saved. Net ₹${formatAmount2(net)}"
                        session.clearBill(cancelInFlight = false)
                        delay(700)
                        onSaved()
                    }
                    .onFailure { error = it.message ?: "Purchase bill not saved." }
            } finally {
                if (session.workJob === self) {
                    session.workJob = null
                }
                saving = false
            }
        }
        session.workJob = job
    }

    // ───────────────────────── UI ─────────────────────────
    val imeVisible = WindowInsets.isImeVisible
    val tableMaxHeight = (config.screenHeightDp * 0.52f).coerceIn(220f, 560f).dp
    val wide = config.screenWidthDp >= 600

    Column(Modifier.fillMaxSize().imePadding()) {
        // Header
        Row(
            Modifier.fillMaxWidth().padding(horizontal = CompactScreenPadding, vertical = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(
                onClick = {
                    // Always allow leaving — cancel stuck AI extract first.
                    if (busy || session.workJob?.isActive == true) {
                        session.cancelWork("Import cancelled.")
                    }
                    if (verify) resetToSource() else onBack()
                },
                modifier = Modifier.size(34.dp),
            ) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, "Back", Modifier.size(20.dp), tint = PharmaSlate)
            }
            Column(Modifier.weight(1f)) {
                Text("Import Bill / CSV", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                Text(
                    if (verify) "Verify & save · ${report?.source?.label.orEmpty()}" else "Upload, scan or fetch a distributor bill",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            SalesLogIconAction(onClick = { showPtrCalc = true }, contentDescription = "PTR/PTS calculator") {
                Icon(Icons.Default.Calculate, null, Modifier.size(19.dp), tint = PharmaTeal)
            }
            if (vm.hasFullAccess() || vm.canEdit("PURCHASE")) {
                SalesLogIconAction(onClick = onNewPurchase, contentDescription = "New purchase") {
                    Icon(Icons.Default.Add, null, Modifier.size(20.dp), tint = PharmaTeal)
                }
            }
            SalesLogIconAction(onClick = onSaved, contentDescription = "Purchases") {
                Icon(Icons.AutoMirrored.Filled.ListAlt, null, Modifier.size(19.dp), tint = PharmaTeal)
            }
        }

        Column(
            Modifier
                .weight(1f)
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = CompactScreenPadding, vertical = 4.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            error?.let { ErrorBanner(it) { error = null } }
            toast?.let { msg ->
                LaunchedEffect(msg) {
                    delay(3500)
                    if (toast == msg) toast = null
                }
                Surface(color = Color(0xFFDCFCE7), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                    Text(msg, Modifier.padding(horizontal = 10.dp, vertical = 6.dp), style = MaterialTheme.typography.labelSmall, color = Color(0xFF166534))
                }
            }

            if (!canEdit) {
                ImportNotice("Import Bill needs Edit rights for Import Bill or Purchase. Ask your admin in My Profile → User Rights.", warn = true)
            } else if (!verify) {
                // ── Source step ──
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    ImportPanel.entries.forEach { p ->
                        ImportTab(p, selected = panel == p) {
                            setPanel(p)
                            error = null
                        }
                    }
                }
                ImportCard {
                    when (panel) {
                        ImportPanel.Upload -> {
                            ImportNotice("Retail Upload PO: choose the distributor and the ERP format, then pick the purchase CSV exported from the distributor's software. Products are matched with Product Master automatically.")
                            PPickField(
                                label = "Distributor *",
                                value = supplierName,
                                placeholder = if (distributorsLoading) "Loading distributors…" else "Select distributor…",
                                onClick = { supplierSheet = true },
                                modifier = Modifier.fillMaxWidth(),
                            )
                            POptionDropdown("Select format *", retailFormat, RetailPurchaseCsv.FORMATS, { retailFormat = it }, Modifier.fillMaxWidth())
                            ImportActionButton("Choose CSV file", Icons.Default.FolderOpen, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                                if (supplierId <= 0) error = "Distributor is required before uploading CSV." else uploadPicker.launch("*/*")
                            }
                        }
                        ImportPanel.Scan -> {
                            ImportNotice("Scan & Bill: upload the bill PDF / image (max $IMPORT_MAX_UPLOAD_MB MB) or take a photo. AI reads the lines, then tap “Match with master”.")
                            if (!aiConfigured) ImportNotice(aiMessage ?: "AI is not configured on the server.", warn = true)
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                ImportActionButton("Choose file", Icons.Default.FolderOpen, enabled = !busy && aiConfigured) {
                                    billPicker.launch(arrayOf("application/pdf", "image/*"))
                                }
                                ImportActionButton("Take photo", Icons.Default.CameraAlt, enabled = !busy && aiConfigured, outlined = true) { launchCamera() }
                            }
                        }
                        ImportPanel.Ai -> {
                            ImportNotice("Upload a PDF or image of the distributor invoice (max $IMPORT_MAX_UPLOAD_MB MB). PDF pages are converted to sharp images and AI extracts product, batch, expiry, qty, free, rate, MRP, GST, pack and HSN for verification. Keep the app open — large bills can take a few minutes.")
                            if (!aiConfigured) ImportNotice(aiMessage ?: "AI is not configured on the server.", warn = true)
                            ImportActionButton("Choose PDF / image", Icons.Default.AutoAwesome, enabled = !busy && aiConfigured, modifier = Modifier.fillMaxWidth()) {
                                billPicker.launch(arrayOf("application/pdf", "image/*"))
                            }
                        }
                        ImportPanel.Csv -> {
                            ImportNotice("For Sale_*.csv (Medivision H/T rows) prefer Auto-detect or Medivision. After parsing you get a full Import Report, then Verify & Save. Gross = Qty × Rate; Net = Taxable + GST. Use .csv, .tsv or .xlsx (max $IMPORT_MAX_UPLOAD_MB MB).")
                            POptionDropdown("1. ERP format", serverFormat, serverFormats, { serverFormat = it }, Modifier.fillMaxWidth(), display = { serverFormatLabels[it] ?: it })
                            ImportActionButton("2. Choose CSV / Excel", Icons.Default.FolderOpen, enabled = !busy, modifier = Modifier.fillMaxWidth()) { csvPicker.launch("*/*") }
                        }
                        ImportPanel.Email -> {
                            ImportNotice("Connect Gmail, pick a recent CSV / Excel attachment, then verify before saving.")
                            if (!gmailConfigured) {
                                ImportNotice(gmailMessage ?: "Gmail import is not configured on the server.", warn = true)
                            } else {
                                FlowRow(
                                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                                    verticalArrangement = Arrangement.spacedBy(6.dp),
                                ) {
                                    if (!gmailLinked) {
                                        ImportActionButton("Connect Gmail", Icons.Default.Link) { connectGmail() }
                                        ImportActionButton("I've connected — refresh", Icons.Default.Refresh, outlined = true) { refreshGmailStatus(true) }
                                    } else {
                                        ImportBadge("Connected", OkGreen)
                                        gmailEmail?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = PharmaMuted) }
                                        ImportActionButton("Refresh", Icons.Default.Refresh, enabled = !busy, outlined = true) { loadAttachments() }
                                        TextButton(onClick = { disconnectGmail() }) {
                                            Text("Disconnect", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.error)
                                        }
                                    }
                                }
                                POptionDropdown("Parse format", serverFormat, serverFormats, { serverFormat = it }, Modifier.fillMaxWidth(), display = { serverFormatLabels[it] ?: it })
                                if (gmailLinked) {
                                    if (attachments.isEmpty() && !busy) {
                                        ImportNotice("No CSV / Excel attachments found in the last 30 days${gmailEmail?.let { " for $it" }.orEmpty()}. Confirm this is the inbox that received the bill, then tap Refresh.")
                                    }
                                    attachments.forEach { a ->
                                        GmailAttachmentRow(a, enabled = !busy) { importAttachment(a) }
                                    }
                                }
                            }
                        }
                    }
                    fileName?.takeIf { panel != ImportPanel.Email }?.let {
                        Text("Last file: $it", style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    if (busy) {
                        Row(
                            Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            BusyRow(busyLabel)
                            Spacer(Modifier.weight(1f))
                            TextButton(onClick = { session.cancelWork("Import cancelled.") }) {
                                Text("Cancel", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.error)
                            }
                        }
                    }
                }
                if (lines.isNotEmpty()) {
                    ImportActionButton("Continue verifying ${lines.size} line(s)", Icons.Default.CheckCircle, outlined = true, modifier = Modifier.fillMaxWidth()) { verify = true }
                }
            } else {
                // ── Verify step ──
                report?.let { rep ->
                    ImportCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("IMPORT REPORT", Modifier.weight(1f), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
                            TextButton(onClick = { shareReport() }, contentPadding = PaddingValues(horizontal = 6.dp)) {
                                Icon(Icons.Default.FileDownload, null, Modifier.size(15.dp))
                                Spacer(Modifier.width(3.dp))
                                Text("Report", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold)
                            }
                            if (unmatchedCount > 0) {
                                TextButton(onClick = { shareUnmatched() }, contentPadding = PaddingValues(horizontal = 6.dp)) {
                                    Icon(Icons.Default.Warning, null, Modifier.size(15.dp), tint = Color(0xFFD97706))
                                    Spacer(Modifier.width(3.dp))
                                    Text("Unmatched", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = Color(0xFFB45309))
                                }
                            }
                        }
                        val kpis = listOf(
                            "File" to rep.filename,
                            "Format" to rep.format + if (rep.requested.isNotBlank() && rep.requested != rep.format && rep.requested != "Auto") " (asked ${rep.requested})" else "",
                            "Lines" to lines.size.toString(),
                            "Matched" to matchedCount.toString(),
                            "Unmatched" to unmatchedCount.toString(),
                            "Bill net" to formatAmount2(billNet),
                        )
                        val perRow = if (wide) 6 else 3
                        kpis.chunked(perRow).forEach { row ->
                            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                row.forEach { (k, v) -> SalesLogKpiChip(k, v, Modifier.weight(1f)) }
                                repeat(perRow - row.size) { Spacer(Modifier.weight(1f)) }
                            }
                        }
                        if (rep.aiFallback) ImportNotice("OpenAI fallback used${rep.aiError?.let { ": $it" }.orEmpty()}.", warn = true)
                        if (rep.source == ImportPanel.Upload && csvRawText.isNotBlank()) {
                            POptionDropdown(
                                "Re-read file as format",
                                retailFormat,
                                RetailPurchaseCsv.FORMATS,
                                { f ->
                                    retailFormat = f
                                    scope.launch { loadRetailCsv(csvRawText, rep.filename) }
                                },
                                Modifier.fillMaxWidth(),
                            )
                        }
                    }
                }

                PSection(
                    title = "Invoice details",
                    expanded = headerExpanded,
                    onToggle = { headerExpanded = !headerExpanded },
                    summary = listOf(invoiceNo, supplierName, cashCredit).filter { it.isNotBlank() }.joinToString(" · "),
                ) {
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        SalesFormField(
                            label = "Invoice No *",
                            value = invoiceNo,
                            onValueChange = { invoiceNo = it.uppercase() },
                            modifier = Modifier.weight(1f),
                            isError = invoiceDup != null || invoiceNo.isBlank(),
                        )
                        PDateField("Invoice date *", invoiceDate, { invoiceDate = it }, Modifier.weight(1f))
                    }
                    invoiceDup?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.error) }
                    PPickField(
                        label = "Distributor *",
                        value = supplierName,
                        placeholder = if (distributorsLoading) "Loading distributors…" else "Select distributor…",
                        onClick = { supplierSheet = true },
                        modifier = Modifier.fillMaxWidth(),
                    )
                    if (supplierName.isNotBlank() && supplierId <= 0) {
                        Text("Not in Distributor Master — pick from the list or add it in Masters → Distributor.", style = MaterialTheme.typography.labelSmall, color = Color(0xFFB45309))
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        POptionDropdown("Cash/Credit", cashCredit, PPayModes, { cashCredit = it }, Modifier.weight(1f))
                        POptionDropdown(
                            "Total bill CD",
                            cdMode,
                            listOf("Percentage", "Amount"),
                            { mode ->
                                cdMode = mode
                                if (mode == "Amount") {
                                    if (grossBillAmt <= 0) grossBillAmt = tGross
                                    recalcCdFromAmount()
                                }
                            },
                            Modifier.weight(1f),
                        )
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        if (cdMode == "Amount") {
                            PNumField("Gross bill amt", grossBillAmt, { grossBillAmt = it; recalcCdFromAmount() }, Modifier.weight(1f))
                            PNumField("CD amt", cdAmtInput, { cdAmtInput = it; recalcCdFromAmount() }, Modifier.weight(1f))
                            PReadField("CD %", pNumText(cdPct, false).ifBlank { "0" }, Modifier.weight(0.7f))
                        } else {
                            PNumField("CD % *", cdPct, { cdPct = it; recalcAll(it) }, Modifier.weight(1f))
                            Spacer(Modifier.weight(1f))
                        }
                    }
                    val debitOptions = listOf("") + debitNotes.map { it.str("note_no") }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        POptionDropdown(
                            label = "Return bill deduction",
                            value = debitNoteNo,
                            options = debitOptions,
                            onSelect = { no ->
                                debitNoteNo = no
                                debitAmt = debitNotes.firstOrNull { it.str("note_no") == no }?.amount("amount") ?: 0.0
                            },
                            modifier = Modifier.weight(1f),
                            display = { no ->
                                if (no.isBlank()) {
                                    if (debitNotes.isEmpty()) "No open debit notes" else "— Select debit note —"
                                } else {
                                    debitNotes.firstOrNull { it.str("note_no") == no }?.str("label")?.ifBlank { no } ?: no
                                }
                            },
                        )
                        PReadField("Debit amount", formatAmount2(debitAmt), Modifier.weight(1f))
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PReadField("Bill net", formatAmount2(billNet), Modifier.weight(1f))
                        PReadField("Net after debit", formatAmount2(payable), Modifier.weight(1f), highlight = true)
                    }
                }

                // Verification checklist
                ImportCard {
                    Text("VERIFICATION CHECKS", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        CheckItem("Supplier selected", chkSupplier)
                        CheckItem("Invoice no & date", chkInvoice)
                        CheckItem("Invoice not already used", chkNoDup)
                        CheckItem("At least one line", chkLines)
                        CheckItem("All lines matched", chkMatched)
                        CheckItem("Qty ≥ 1 on every line", chkQty)
                        CheckItem("Rates / MRP ≥ 0", chkRates)
                    }
                }

                // Filter + match actions
                FlowRow(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    PSmallFilter("All ${lines.size}", lineFilter == LineFilter.All) { setLineFilter(LineFilter.All) }
                    PSmallFilter("OK $matchedCount", lineFilter == LineFilter.Matched) { setLineFilter(LineFilter.Matched) }
                    PSmallFilter("Gap $unmatchedCount", lineFilter == LineFilter.Unmatched, warn = true) { setLineFilter(LineFilter.Unmatched) }
                    Spacer(Modifier.weight(1f))
                    ImportActionButton(
                        if (matching) "Matching…" else "Match with master",
                        Icons.Default.Link,
                        enabled = !matching && lines.isNotEmpty(),
                        outlined = matchedCount == lines.size,
                    ) { matchAllWithMaster() }
                }
                if (matching) BusyRow("Matching products…")

                val visible = lines.withIndex().filter { (_, l) ->
                    when (lineFilter) {
                        LineFilter.All -> true
                        LineFilter.Matched -> l.masterProductId != null
                        LineFilter.Unmatched -> l.masterProductId == null
                    }
                }
                ImportLinesTable(
                    rows = visible,
                    maxBodyHeight = tableMaxHeight,
                    onUpdate = { key, change -> updateLine(key, change) },
                    onRates = { fetchRates(it) },
                    onMatch = { matchSheetFor = it },
                    onAdd = { key ->
                        val line = lines.firstOrNull { it.key == key }
                        if (line != null && canQuickAddMaster) {
                            quickName = line.product
                            pendingProductLineKey = key
                            quickMaster = "products"
                        } else {
                            matchSheetFor = key
                        }
                    },
                    onPack = { packSheetFor = it },
                    onRemove = { key -> lines = lines.filterNot { it.key == key } },
                )

                ImportCard {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        PTotalChip("Gross", tGross)
                        PTotalChip("Disc", tDisc)
                        PTotalChip("CD", tCd)
                        PTotalChip("Taxable", tTaxable)
                        PTotalChip("GST", tGst)
                        PTotalChip("Net", billNet)
                        if (debitAmt > 0) PTotalChip("Debit", debitAmt, danger = true)
                        PTotalChip("Payable", payable)
                    }
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    ImportActionButton("Back to source", Icons.AutoMirrored.Filled.ArrowBack, outlined = true, modifier = Modifier.weight(1f)) { resetToSource() }
                    ImportActionButton("Clear bill", Icons.Default.Close, outlined = true, enabled = !saving, modifier = Modifier.weight(1f)) { clearBill() }
                }
            }
            Spacer(Modifier.height(10.dp))
        }

        if (verify && canEdit && !imeVisible) {
            Surface(shadowElevation = 8.dp, color = Color.White) {
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            "${lines.size} line(s) · $matchedCount matched · GST ${formatAmount2(tGst)}",
                            style = MaterialTheme.typography.labelSmall,
                            color = if (unmatchedCount > 0) Color(0xFFB45309) else PharmaMuted,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                        Row(verticalAlignment = Alignment.Bottom) {
                            Text("PAYABLE ", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            Text(formatAmount2(payable), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = PharmaTeal, maxLines = 1)
                        }
                    }
                    Button(
                        onClick = { save() },
                        enabled = !saving && !matching && lines.isNotEmpty(),
                        shape = RoundedCornerShape(8.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal),
                        contentPadding = PaddingValues(horizontal = 14.dp),
                        modifier = Modifier.height(40.dp),
                    ) {
                        if (saving) CircularProgressIndicator(Modifier.size(16.dp), color = Color.White, strokeWidth = 2.dp)
                        else Icon(Icons.Default.Save, null, Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("Confirm & save", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        }
    }

    // ── Distributor picker ──
    if (supplierSheet) {
        var q by remember { mutableStateOf("") }
        val list = remember(q, distributors) {
            val t = q.trim()
            if (t.isEmpty()) distributors else distributors.filter { it.str("name").contains(t, ignoreCase = true) }
        }
        PSearchSheet(
            title = "Select distributor",
            subtitle = "${list.size} distributor(s)",
            query = q,
            onQuery = { q = it.uppercase() },
            placeholder = "Search distributor…",
            loading = distributorsLoading,
            onDismiss = { supplierSheet = false },
        ) {
            items(list.take(300)) { s ->
                PSheetRow(
                    title = s.str("name"),
                    subtitle = listOf(s.str("city"), s.str("gstin")).filter { it.isNotBlank() }.joinToString(" · "),
                    selected = s.jInt("id") == supplierId,
                    onClick = { pickSupplier(s) },
                )
            }
            if (list.isEmpty()) {
                item {
                    Text(
                        if (distributorsLoading) {
                            "Loading distributors…"
                        } else if (q.isNotBlank()) {
                            "No distributor matches “${q.trim()}”"
                        } else {
                            "No distributors found — add one in Distributor Master"
                        },
                        Modifier.padding(12.dp),
                        style = MaterialTheme.typography.bodySmall,
                        color = PharmaMuted,
                    )
                }
            }
            if (canQuickAddMaster) {
                item {
                    MasterAddNewRow(
                        label = masterAddNewLabel("distributors", q.trim().ifBlank { supplierName }),
                        onClick = {
                            quickName = q.trim().ifBlank { supplierName.trim() }
                            supplierSheet = false
                            quickMaster = "distributors"
                        },
                    )
                }
            }
        }
    }

    quickMaster?.let { key ->
        val nameSnapshot = quickName
        MasterQuickCreateSheet(
            vm = vm,
            masterKey = key,
            prefillName = nameSnapshot,
            onDismiss = {
                quickMaster = null
                pendingProductLineKey = null
            },
            onCreated = {
                quickMaster = null
                scope.launch {
                    if (key == "distributors") {
                        val list = runCatching {
                            withContext(Dispatchers.IO) { purchaseRepo.allDistributors() }
                        }.getOrDefault(emptyList())
                        distributors = list.distinctBy { it.str("name").trim().uppercase() }
                        val hit = list.firstOrNull { it.str("name").equals(nameSnapshot, ignoreCase = true) }
                            ?: list.firstOrNull { nameSnapshot.isNotBlank() && it.str("name").contains(nameSnapshot, ignoreCase = true) }
                        if (hit != null) {
                            pickSupplier(hit)
                            toast = "Distributor added and selected."
                        } else {
                            toast = "Distributor created. Select it from the list."
                            supplierSheet = true
                        }
                    } else if (key == "products") {
                        val lineKey = pendingProductLineKey
                        pendingProductLineKey = null
                        val q = nameSnapshot.trim()
                        val hits = if (q.isBlank()) emptyList()
                        else runCatching { withContext(Dispatchers.IO) { importRepo.matchQuery(q, 25) } }.getOrDefault(emptyList())
                        val hit = hits.firstOrNull { it.str("name").equals(nameSnapshot, ignoreCase = true) }
                            ?: hits.firstOrNull()
                        if (hit != null && lineKey != null) {
                            val src = lines.firstOrNull { it.key == lineKey }
                            val csvKey = iNorm(src?.product ?: nameSnapshot)
                            var applied = 0
                            lines = lines.map { l ->
                                if (l.key == lineKey || iNorm(l.product) == csvKey) {
                                    applied++
                                    iApplyHit(l, hit, packOptions, cdPct, listOf(hit))
                                } else l
                            }
                            autoFillMissingPtrPts()
                            toast = if (applied > 1) {
                                "Product added — matched $applied rows with the same name."
                            } else {
                                "Product added and matched."
                            }
                            lines.firstOrNull { it.key == lineKey }?.takeIf { it.ptr <= 0 }?.let { fetchRates(lineKey) }
                        } else {
                            toast = "Product created. Match it from the list."
                            if (lineKey != null) matchSheetFor = lineKey
                        }
                    }
                }
            },
        )
    }

    // ── Product match ──
    matchSheetFor?.let { key ->
        val line = lines.firstOrNull { it.key == key }
        if (line == null) {
            matchSheetFor = null
        } else {
            var q by remember(key) { mutableStateOf(line.product) }
            var hits by remember(key) { mutableStateOf<List<JsonObject>>(emptyList()) }
            var searching by remember(key) { mutableStateOf(false) }
            LaunchedEffect(key, q) {
                val t = q.trim()
                if (t.isEmpty()) {
                    hits = emptyList()
                    return@LaunchedEffect
                }
                delay(250)
                searching = true
                hits = runCatching { withContext(Dispatchers.IO) { importRepo.matchQuery(t, 25) } }.getOrDefault(emptyList())
                searching = false
            }
            fun pick(hit: JsonObject) {
                lines = lines.map { if (it.key == key) iApplyHit(it, hit, packOptions, cdPct) else it }
                matchSheetFor = null
                lines.firstOrNull { it.key == key }?.takeIf { it.ptr <= 0 }?.let { fetchRates(key) }
            }
            PSearchSheet(
                title = "Match product",
                subtitle = "Imported: ${line.product}${if (line.packRaw.isNotBlank()) " · ${line.packRaw}" else ""}",
                query = q,
                onQuery = { q = it.uppercase() },
                placeholder = "Search Product Master…",
                loading = searching,
                onDismiss = { matchSheetFor = null },
            ) {
                if (line.masterProductId != null) {
                    item {
                        PSheetRow("Clear match", "Currently: ${line.matchedName}", selected = false) {
                            lines = lines.map { if (it.key == key) it.copy(masterProductId = null, matchedName = "") else it }
                            matchSheetFor = null
                        }
                    }
                }
                if (line.matches.size > 1) {
                    item { Text("SUGGESTED", Modifier.padding(start = 6.dp, top = 6.dp), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold, color = PharmaMuted) }
                    items(line.matches) { m ->
                        PSheetRow(
                            title = m.str("name"),
                            subtitle = listOf(m.str("pack_size"), m.str("manufacturer")).filter { it.isNotBlank() }.joinToString(" · "),
                            selected = m.jInt("id") == line.masterProductId,
                            onClick = { pick(m) },
                        )
                    }
                    item { Text("SEARCH RESULTS", Modifier.padding(start = 6.dp, top = 6.dp), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold, color = PharmaMuted) }
                }
                items(hits) { h ->
                    PSheetRow(
                        title = h.str("name"),
                        subtitle = listOf(h.str("pack_size"), h.str("manufacturer"), h.amount("default_gst_rate").takeIf { it > 0 }?.let { "GST ${pNumText(it, false)}%" }.orEmpty())
                            .filter { it.isNotBlank() }.joinToString(" · "),
                        selected = h.jInt("id") == line.masterProductId,
                        onClick = { pick(h) },
                    )
                }
                if (hits.isEmpty() && !searching) {
                    item { Text("No matches", Modifier.padding(12.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted) }
                }
                if (canQuickAddMaster) {
                    item {
                        MasterAddNewRow(
                            label = masterAddNewLabel("products", q.trim().ifBlank { line.product }),
                            onClick = {
                                quickName = q.trim().ifBlank { line.product }
                                pendingProductLineKey = key
                                matchSheetFor = null
                                quickMaster = "products"
                            },
                        )
                    }
                }
            }
        }
    }

    // ── Pack picker (line key, or -1 for Add Product form) ──
    var addFormPack by remember { mutableStateOf("") }
    packSheetFor?.let { target ->
        var q by remember(target) { mutableStateOf("") }
        val key = pPackKey(q)
        val filtered = remember(q, packOptions) {
            if (q.isBlank()) packOptions.take(80)
            else packOptions.filter {
                val label = pPackLabel(it).lowercase()
                label.contains(q.lowercase()) || pPackKey(label).contains(key) || pPackKey(it.str("pack")).contains(key)
            }.take(80)
        }
        val current = if (target == -1L) addFormPack else lines.firstOrNull { it.key == target }?.pack.orEmpty()
        fun applyPack(pack: String, units: Int?) {
            packSheetFor = null
            if (target == -1L) {
                addFormPack = pack
            } else {
                updateLine(target) { it.copy(pack = pack, upp = units?.takeIf { u -> u > 0 } ?: iUnits(packOptions, pack, 1)) }
            }
        }
        PSearchSheet(
            title = "Pack size",
            subtitle = if (current.isNotBlank()) "Current: $current" else "Choose or type a pack (e.g. 1x10, 100ML)",
            query = q,
            onQuery = { q = it },
            placeholder = "Search pack…",
            loading = false,
            onDismiss = { packSheetFor = null },
        ) {
            if (q.isNotBlank() && filtered.none { pPackKey(it.str("pack")) == key }) {
                item { PSheetRow("Use “${q.trim()}”", "Units per pack worked out from the label", selected = false) { applyPack(q.trim(), null) } }
            }
            items(filtered) { p ->
                PSheetRow(
                    title = pPackLabel(p),
                    subtitle = listOf(p.jInt("units").takeIf { it > 0 }?.let { "$it unit(s)" }.orEmpty(), p.str("form")).filter { it.isNotBlank() }.joinToString(" · "),
                    selected = pPackKey(p.str("pack")) == pPackKey(current),
                    onClick = { applyPack(p.str("pack").trim(), p.jInt("units")) },
                )
            }
            if (filtered.isEmpty() && q.isBlank()) {
                item { Text("No pack sizes loaded.", Modifier.padding(12.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted) }
            }
        }
    }

    if (showPtrCalc) PtrPtsCalculatorDialog { showPtrCalc = false }
}

private fun formatSaleDateSafe(iso: String): String =
    runCatching { LocalDate.parse(iso).let { "%02d-%02d-%04d".format(it.dayOfMonth, it.monthValue, it.year) } }.getOrDefault(iso)

// ───────────────────────── Lines table ─────────────────────────

private object ILCols {
    val no = 22.dp
    val match = 84.dp
    val hsn = 64.dp
    val product = 150.dp
    val batch = 78.dp
    val qty = 46.dp
    val num = 60.dp
    val pack = 70.dp
    val mfg = 82.dp
    val exp = 78.dp
    val amt = 66.dp
    val gst = 50.dp
    val net = 74.dp
    val action = 30.dp
    val gap = 4.dp
}

@Composable
private fun ImportLinesTable(
    rows: List<IndexedValue<ImportLine>>,
    maxBodyHeight: Dp,
    onUpdate: (Long, (ImportLine) -> ImportLine) -> Unit,
    onRates: (Long) -> Unit,
    onMatch: (Long) -> Unit,
    onAdd: (Long) -> Unit,
    onPack: (Long) -> Unit,
    onRemove: (Long) -> Unit,
) {
    val scroll = rememberScrollState()
    BillLinesFrame {
        Row(
            Modifier.fillMaxWidth().horizontalScroll(scroll).background(BillGridHeaderBg),
            horizontalArrangement = Arrangement.spacedBy(ILCols.gap),
        ) {
            BillGridTh("#", ILCols.no)
            BillGridTh("MATCH", ILCols.match)
            BillGridTh("PRODUCT", ILCols.product)
            BillGridTh("HSN", ILCols.hsn)
            BillGridTh("BATCH", ILCols.batch)
            BillGridTh("QTY", ILCols.qty, alignEnd = true)
            BillGridTh("FREE", ILCols.qty, alignEnd = true)
            BillGridTh("MRP", ILCols.num, alignEnd = true)
            BillGridTh("RATE", ILCols.num, alignEnd = true)
            BillGridTh("PTR", ILCols.num, alignEnd = true)
            BillGridTh("PTS", ILCols.num, alignEnd = true)
            BillGridTh("PACK", ILCols.pack)
            BillGridTh("MFG", ILCols.mfg)
            BillGridTh("EXP", ILCols.exp)
            BillGridTh("GROSS", ILCols.amt, alignEnd = true)
            BillGridTh("DISC%", ILCols.qty, alignEnd = true)
            BillGridTh("DISC₹", ILCols.num, alignEnd = true)
            BillGridTh("CD₹", ILCols.amt, alignEnd = true)
            BillGridTh("GST", ILCols.gst)
            BillGridTh("TAXABLE", ILCols.amt, alignEnd = true)
            BillGridTh("GST₹", ILCols.amt, alignEnd = true)
            BillGridTh("NET", ILCols.net, alignEnd = true)
            BillGridTh("", ILCols.action)
        }
        HorizontalDivider(color = PharmaBorder)
        if (rows.isEmpty()) {
            Text("No lines for this filter.", Modifier.fillMaxWidth().padding(16.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
        }
        LazyColumn(Modifier.fillMaxWidth().heightIn(max = maxBodyHeight)) {
            itemsIndexed(rows, key = { _, r -> r.value.key }) { pos, (index, l) ->
                Column {
                    val matched = l.masterProductId != null
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .background(if (matched) Color.Transparent else UnmatchedBg)
                            .horizontalScroll(scroll)
                            .padding(vertical = 3.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(ILCols.gap),
                    ) {
                        BillGridCellText("${index + 1}", ILCols.no, color = PharmaMuted)
                        Row(Modifier.width(ILCols.match), horizontalArrangement = Arrangement.spacedBy(3.dp), verticalAlignment = Alignment.CenterVertically) {
                            if (matched) {
                                Icon(Icons.Default.CheckCircle, "Matched", Modifier.size(18.dp).clickable { onMatch(l.key) }, tint = OkGreen)
                                Text("Change", Modifier.clickable { onMatch(l.key) }, style = MaterialTheme.typography.labelSmall, color = PharmaTeal)
                            } else {
                                MiniCellButton("Match", Color(0xFFB45309)) { onMatch(l.key) }
                                MiniCellButton("Add", PharmaTeal) { onAdd(l.key) }
                            }
                        }
                        Column(Modifier.width(ILCols.product).clickable { onMatch(l.key) }) {
                            Text(
                                if (matched) l.matchedName else l.product,
                                style = MaterialTheme.typography.labelSmall,
                                fontWeight = FontWeight.SemiBold,
                                color = if (matched) PharmaSlate else Color(0xFF92400E),
                                maxLines = 2,
                                overflow = TextOverflow.Ellipsis,
                            )
                            val sub = buildList {
                                if (matched && l.product.isNotBlank() && !l.product.equals(l.matchedName, true)) add(l.product)
                                if (l.matches.size > 1) add("${l.matches.size} options ▾")
                            }.joinToString(" · ")
                            if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        PCellInput(l.hsn, { v -> onUpdate(l.key) { it.copy(hsn = v.filter(Char::isDigit).take(8)) } }, ILCols.hsn, KeyboardType.Number)
                        PCellInput(l.batch, { v -> onUpdate(l.key) { it.copy(batch = v.uppercase()) } }, ILCols.batch)
                        PCellNum(l.qty.toDouble(), { v -> onUpdate(l.key) { it.copy(qty = v.toInt(), srcGross = 0.0, srcDisc = 0.0) } }, ILCols.qty, isInt = true)
                        PCellNum(l.free.toDouble(), { v -> onUpdate(l.key) { it.copy(free = v.toInt()) } }, ILCols.qty, isInt = true)
                        PCellNum(l.mrp, { v -> onUpdate(l.key) { it.copy(mrp = v) }; onRates(l.key) }, ILCols.num)
                        PCellNum(l.rate, { v -> onUpdate(l.key) { it.copy(rate = v, srcGross = 0.0, srcDisc = 0.0) } }, ILCols.num)
                        PCellNum(l.ptr, { v -> onUpdate(l.key) { it.copy(ptr = v) } }, ILCols.num)
                        PCellNum(l.pts, { v -> onUpdate(l.key) { it.copy(pts = v) } }, ILCols.num)
                        Box(
                            Modifier
                                .width(ILCols.pack)
                                .height(30.dp)
                                .clip(RoundedCornerShape(5.dp))
                                .background(Color.White)
                                .border(1.dp, if (l.pack.isBlank()) MaterialTheme.colorScheme.error else Color(0xFFE2E8F0), RoundedCornerShape(5.dp))
                                .clickable { onPack(l.key) }
                                .padding(horizontal = 4.dp),
                            contentAlignment = Alignment.CenterStart,
                        ) {
                            Text(
                                l.pack.ifBlank { "Pack" },
                                style = MaterialTheme.typography.labelSmall,
                                color = if (l.pack.isBlank()) PharmaMuted else PharmaSlate,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                            )
                        }
                        PCellInput(l.mfg, { v -> onUpdate(l.key) { it.copy(mfg = v.uppercase()) } }, ILCols.mfg)
                        PCellExpiry(l.expiry, { ym -> onUpdate(l.key) { it.copy(expiry = ym) } }, ILCols.exp)
                        BillGridCellText(formatAmount2(l.gross), ILCols.amt, alignEnd = true)
                        PCellNum(l.discPct, { v -> onUpdate(l.key) { it.copy(discPct = v, srcDisc = 0.0) } }, ILCols.qty)
                        PCellNum(l.discAmt, { v ->
                            onUpdate(l.key) { it.copy(srcDisc = v, discPct = 0.0) }
                        }, ILCols.num)
                        BillGridCellText(formatAmount2(l.cdAmt), ILCols.amt, alignEnd = true, color = PharmaMuted)
                        PCellGst(l.gst, { g -> onUpdate(l.key) { it.copy(gst = g) }; onRates(l.key) }, ILCols.gst)
                        BillGridCellText(formatAmount2(l.taxable), ILCols.amt, alignEnd = true)
                        BillGridCellText(formatAmount2(l.gstAmt), ILCols.amt, alignEnd = true)
                        BillGridCellText(formatAmount2(l.net), ILCols.net, alignEnd = true, color = Color(0xFF0284C7), fontWeight = FontWeight.SemiBold)
                        IconButton(onClick = { onRemove(l.key) }, modifier = Modifier.size(ILCols.action)) {
                            Icon(Icons.Default.Close, "Remove", Modifier.size(16.dp), tint = MaterialTheme.colorScheme.error)
                        }
                    }
                    if (pos < rows.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.45f))
                }
            }
        }
    }
}

@Composable
private fun MiniCellButton(text: String, color: Color, onClick: () -> Unit) {
    Text(
        text,
        Modifier
            .clip(RoundedCornerShape(4.dp))
            .border(1.dp, color.copy(alpha = 0.6f), RoundedCornerShape(4.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 5.dp, vertical = 3.dp),
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.SemiBold,
        color = color,
    )
}

// ───────────────────────── Small building blocks ─────────────────────────

@Composable
private fun ImportCard(content: @Composable ColumnScope.() -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(Color.White)
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .padding(8.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
        content = content,
    )
}

@Composable
private fun ImportTab(p: ImportPanel, selected: Boolean, onClick: () -> Unit) {
    Row(
        Modifier
            .clip(RoundedCornerShape(18.dp))
            .background(if (selected) PharmaTeal else Color.White)
            .border(1.dp, if (selected) PharmaTeal else PharmaBorder, RoundedCornerShape(18.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 10.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(p.icon, null, Modifier.size(15.dp), tint = if (selected) Color.White else PharmaTeal)
        Spacer(Modifier.width(5.dp))
        Text(p.label, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = if (selected) Color.White else PharmaSlate)
    }
}

@Composable
private fun ImportNotice(text: String, warn: Boolean = false) {
    Text(
        text,
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .background(if (warn) Color(0xFFFEF3C7) else Color(0xFFF1F5F9))
            .padding(horizontal = 8.dp, vertical = 6.dp),
        style = MaterialTheme.typography.labelSmall,
        color = if (warn) Color(0xFF92400E) else PharmaMuted,
    )
}

@Composable
private fun ImportActionButton(
    text: String,
    icon: ImageVector,
    enabled: Boolean = true,
    outlined: Boolean = false,
    modifier: Modifier = Modifier,
    onClick: () -> Unit,
) {
    // Min height (not fixed) so a wrapped label is never clipped; disabled keeps readable contrast.
    val shape = RoundedCornerShape(8.dp)
    val padding = PaddingValues(horizontal = 12.dp, vertical = 6.dp)
    val sized = modifier.heightIn(min = 38.dp)
    val label: @Composable RowScope.(Color) -> Unit = { tint ->
        Icon(icon, null, Modifier.size(16.dp), tint = tint)
        Spacer(Modifier.width(6.dp))
        Text(
            text,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.SemiBold,
            color = tint,
            textAlign = TextAlign.Center,
            maxLines = 2,
        )
    }
    if (outlined) {
        OutlinedButton(
            onClick = onClick,
            enabled = enabled,
            shape = shape,
            border = BorderStroke(1.dp, if (enabled) PharmaTeal else PharmaBorder),
            colors = ButtonDefaults.outlinedButtonColors(containerColor = Color.White),
            contentPadding = padding,
            modifier = sized,
        ) { label(if (enabled) PharmaTeal else PharmaMuted) }
    } else {
        Button(
            onClick = onClick,
            enabled = enabled,
            shape = shape,
            colors = ButtonDefaults.buttonColors(
                containerColor = PharmaTeal,
                contentColor = Color.White,
                disabledContainerColor = Color(0xFFCBD5E1),
                disabledContentColor = Color(0xFF475569),
            ),
            contentPadding = padding,
            modifier = sized,
        ) { label(if (enabled) Color.White else Color(0xFF475569)) }
    }
}

@Composable
private fun BusyRow(label: String) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        CircularProgressIndicator(Modifier.size(16.dp), color = PharmaTeal, strokeWidth = 2.dp)
        Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
    }
}

@Composable
private fun ImportBadge(text: String, color: Color) {
    Text(
        text,
        Modifier.clip(RoundedCornerShape(4.dp)).background(color.copy(alpha = 0.12f)).padding(horizontal = 6.dp, vertical = 2.dp),
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.SemiBold,
        color = color,
    )
}

@Composable
private fun CheckItem(label: String, ok: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            if (ok) Icons.Default.CheckCircle else Icons.Default.Cancel,
            null,
            Modifier.size(14.dp),
            tint = if (ok) OkGreen else MaterialTheme.colorScheme.error,
        )
        Spacer(Modifier.width(3.dp))
        Text(label, style = MaterialTheme.typography.labelSmall, color = if (ok) PharmaSlate else MaterialTheme.colorScheme.error)
    }
}

@Composable
private fun PSmallFilter(text: String, selected: Boolean, warn: Boolean = false, onClick: () -> Unit) {
    val accent = if (warn) Color(0xFFD97706) else PharmaTeal
    Text(
        text,
        Modifier
            .clip(RoundedCornerShape(14.dp))
            .background(if (selected) accent else Color.White)
            .border(1.dp, if (selected) accent else PharmaBorder, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 6.dp),
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.SemiBold,
        color = if (selected) Color.White else PharmaSlate,
    )
}

@Composable
private fun GmailAttachmentRow(a: JsonObject, enabled: Boolean, onImport: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .border(1.dp, PharmaBorder, RoundedCornerShape(6.dp))
            .padding(horizontal = 8.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(a.str("filename"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(a.str("subject"), style = MaterialTheme.typography.labelSmall, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                listOf(a.str("from"), a.str("date")).filter { it.isNotBlank() }.joinToString(" · "),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        Spacer(Modifier.width(6.dp))
        ImportActionButton("Import", Icons.Default.FileDownload, enabled = enabled, onClick = onImport)
    }
}

// ───────────────────────── Add product (Product Master) ─────────────────────────

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun AddImportProductSheet(
    source: ImportLine,
    pack: String,
    manufacturers: List<String>,
    onPickPack: () -> Unit,
    onDismiss: () -> Unit,
    onSave: (JsonObject, (String?) -> Unit) -> Unit,
) {
    var type by remember { mutableStateOf("Medicine") }
    var name by remember { mutableStateOf(source.product.uppercase()) }
    var schedule by remember { mutableStateOf("OTC") }
    var category by remember { mutableStateOf("Allopathy") }
    var dosage by remember { mutableStateOf("") }
    var strength by remember { mutableStateOf("") }
    var mrp by remember { mutableDoubleStateOf(source.mrp) }
    var gst by remember { mutableDoubleStateOf(if (source.gst > 0) source.gst else 5.0) }
    var hsn by remember { mutableStateOf(source.hsn) }
    var packing by remember { mutableStateOf("") }
    var mfg by remember { mutableStateOf(source.mfg) }
    var saving by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf<String?>(null) }
    val showDosage = type != "General" && type != "Food/Nutra"
    val showMfg = type != "General"

    fun submit() {
        val n = name.trim().uppercase()
        val p = pack.trim()
        err = when {
            n.isEmpty() -> "Product Name is required."
            p.isEmpty() -> "Pack Size is required."
            mrp < 0 -> "MRP is required."
            else -> null
        }
        if (err != null) return
        val body = JsonObject().apply {
            addProperty("product_type", type)
            addProperty("name", n)
            addProperty("pack_size", p)
            addProperty("mrp", mrp)
            addProperty("schedule_category", if (type == "Medicine") schedule else "OTC")
            addProperty("default_gst_rate", gst)
            addProperty("units_per_pack", pUnitsFromPack(p))
            addProperty("is_active", 1)
            if (category.isNotBlank()) addProperty("product_category", category)
            if (showDosage && dosage.isNotBlank()) addProperty("dosage_form", dosage.trim().uppercase())
            if (showDosage && strength.isNotBlank()) addProperty("strength", strength.trim().uppercase())
            if (packing.isNotBlank()) addProperty("packing_desc", packing.trim().uppercase())
            if (hsn.isNotBlank()) addProperty("hsn_code", hsn.trim())
            if (showMfg && mfg.isNotBlank()) addProperty("manufacturer", mfg.trim())
        }
        saving = true
        onSave(body) { e ->
            saving = false
            err = e
        }
    }

    ModalBottomSheet(
        onDismissRequest = { if (!saving) onDismiss() },
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 12.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Text("Add product", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            Text("Imported as: ${source.product}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            err?.let { ErrorBanner(it) { err = null } }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                POptionDropdown("Product type *", type, ProductTypes, { type = it }, Modifier.weight(1f))
                if (type == "Medicine") POptionDropdown("Schedule *", schedule, PScheduleOptions, { schedule = it }, Modifier.weight(1f))
            }
            SalesFormField("Product name *", name, { name = it.uppercase() }, Modifier.fillMaxWidth(), isError = name.isBlank())
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                POptionDropdown("Margin category", category, listOf("") + MarginCategories, { category = it }, Modifier.weight(1f), display = { it.ifBlank { "— Optional —" } })
                PPickField("Pack size *", pack, "Select pack…", onPickPack, Modifier.weight(1f))
            }
            if (showDosage) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    POptionDropdown("Dosage", dosage, listOf("") + DosageForms, { dosage = it }, Modifier.weight(1f), display = { it.ifBlank { "— Select —" } })
                    SalesFormField("Strength", strength, { strength = it.uppercase() }, Modifier.weight(1f), placeholder = "e.g. 500 MG")
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PNumField("MRP *", mrp, { mrp = it }, Modifier.weight(1f))
                POptionDropdown("GST %", pNumText(gst, false).ifBlank { "0" }, PGstOptions.map { pNumText(it, false).ifBlank { "0" } }, { gst = it.toDoubleOrNull() ?: 0.0 }, Modifier.weight(1f))
                SalesFormField("HSN", hsn, { hsn = it.filter(Char::isDigit).take(8) }, Modifier.weight(1f), keyboardType = KeyboardType.Number)
            }
            SalesFormField("Packing desc.", packing, { packing = it.uppercase() }, Modifier.fillMaxWidth())
            if (showMfg) {
                SalesFormField("Manufacturer name", mfg, { mfg = it }, Modifier.fillMaxWidth(), placeholder = "Type or pick manufacturer")
                val q = mfg.trim()
                val sugg = if (q.length >= 2) manufacturers.filter { it.contains(q, true) && !it.equals(q, true) }.take(6) else emptyList()
                if (sugg.isNotEmpty()) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        sugg.forEach { m ->
                            Text(
                                m,
                                Modifier
                                    .clip(RoundedCornerShape(12.dp))
                                    .background(Color(0xFFF0FDFA))
                                    .clickable { mfg = m }
                                    .padding(horizontal = 8.dp, vertical = 3.dp),
                                style = MaterialTheme.typography.labelSmall,
                                color = PharmaTeal,
                            )
                        }
                    }
                }
            }
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End)) {
                OutlinedButton(onClick = onDismiss, enabled = !saving, shape = RoundedCornerShape(8.dp)) { Text("Close") }
                Button(
                    onClick = { submit() },
                    enabled = !saving,
                    shape = RoundedCornerShape(8.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal),
                ) {
                    if (saving) CircularProgressIndicator(Modifier.size(16.dp), color = Color.White, strokeWidth = 2.dp)
                    else Icon(Icons.Default.Save, null, Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                    Text("Submit")
                }
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}

// ───────────────────────── PTR / PTS calculator ─────────────────────────

@Composable
private fun PtrPtsCalculatorDialog(onDismiss: () -> Unit) {
    var mrp by remember { mutableDoubleStateOf(0.0) }
    var margin by remember { mutableDoubleStateOf(0.0) }
    var gst by remember { mutableDoubleStateOf(5.0) }
    val base = if (gst > 0) mrp / (1 + gst / 100) else mrp
    val result = iR2(max(0.0, base - margin))
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("PTR / PTS calculator", style = MaterialTheme.typography.titleSmall) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    PNumField("MRP", mrp, { mrp = it }, Modifier.weight(1f))
                    PNumField("Retailer margin", margin, { margin = it }, Modifier.weight(1f))
                    PNumField("GST %", gst, { gst = it }, Modifier.weight(1f))
                }
                Surface(color = Color(0xFFDCFCE7), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                    Text(
                        "PTR = PTS = ${formatAmount2(result)}",
                        Modifier.padding(10.dp),
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = Color(0xFF166534),
                    )
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Close") } },
    )
}
