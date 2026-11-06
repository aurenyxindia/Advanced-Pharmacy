package `in`.paperlesspharmacy.advanced.ui.screens

import android.content.Context
import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.ChevronLeft
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.FileDownload
import androidx.compose.material.icons.filled.LocalOffer
import androidx.compose.material.icons.filled.Print
import androidx.compose.material.icons.filled.Save
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.UploadFile
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.CompactScreenPadding
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaBorder
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaMuted
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaSlate
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTeal
import `in`.paperlesspharmacy.advanced.ui.theme.SalesDenseFieldHeight
import `in`.paperlesspharmacy.advanced.ui.theme.CompactFormHeaderGap
import `in`.paperlesspharmacy.advanced.ui.util.formatAmount2
import `in`.paperlesspharmacy.advanced.ui.util.formatExpiryMmYy
import `in`.paperlesspharmacy.advanced.ui.util.formatSaleDate
import `in`.paperlesspharmacy.advanced.ui.util.parseExpiryYm
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.math.BigDecimal
import java.time.Instant
import java.time.LocalDate
import java.time.YearMonth
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import kotlin.math.floor
import kotlin.math.max
import kotlin.math.round

// ───────────────────────── Line model & calculations (web purchase-entry parity) ─────────────────────────

data class PurchaseLineDraft(
    val key: Long = System.nanoTime(),
    val bindingId: Int? = null,
    val masterProductId: Int? = null,
    val productName: String = "",
    val schedule: String = "OTC",
    val category: String = "",
    val hsn: String = "",
    val batch: String = "",
    /** yyyy-MM */
    val expiry: String = "",
    val shelf: String = "",
    val pack: String = "",
    val qty: Int = 1,
    val upp: Int = 1,
    val free: Int = 0,
    val rate: Double = 0.0,
    val mrp: Double = 0.0,
    val ptr: Double = 0.0,
    val pts: Double = 0.0,
    val gst: Double = 12.0,
    val discPct: Double = 0.0,
    val discAmt: Double = 0.0,
    val schemePct: Double = 0.0,
)

private enum class PField { Qty, Rate, DiscPct, DiscAmt, Scheme, Other }

internal val PGstOptions = listOf(0.0, 5.0, 12.0, 18.0, 28.0)
internal val PScheduleOptions = listOf("OTC", "H", "H1", "X", "G", "RESTRICTED")
private val PCategoryOptions = listOf("Allopathy", "Ayurvedic", "Generic", "Surgical", "Cosmetic")
internal val PPayModes = listOf("Cash", "UPI", "Cheque", "Credit")
private val PHsnRegex = Regex("^\\d{4,8}$")
internal val PFieldBorder = Color(0xFFCBD5E1)
internal val PReadBg = Color(0xFFF1F5F9)

internal fun pR2(v: Double): Double = round(v * 100.0) / 100.0
private fun pGross(l: PurchaseLineDraft): Double = l.rate * l.qty
private fun pDisc(l: PurchaseLineDraft): Double =
    if (l.discAmt > 0) pR2(l.discAmt) else pR2(pGross(l) * l.discPct / 100.0)
private fun pTaxable(l: PurchaseLineDraft): Double = pGross(l) - pDisc(l)
private fun pTotal(l: PurchaseLineDraft): Double = pTaxable(l) * (1 + l.gst / 100.0)
private fun pNetRate(l: PurchaseLineDraft): Double {
    val units = (l.qty + l.free) * l.upp.coerceAtLeast(1)
    return if (units > 0) (pGross(l) - pDisc(l)) / units else 0.0
}

/** Scheme % → free packs; disc % ↔ disc ₹ stay in sync (Retail behaviour). */
private fun pSync(l: PurchaseLineDraft, field: PField): PurchaseLineDraft {
    var n = l
    if (field == PField.Scheme || (field == PField.Qty && n.schemePct > 0)) {
        n = n.copy(free = floor(max(0, n.qty) * (max(0.0, n.schemePct) / 100.0)).toInt())
    }
    val gross = pGross(n)
    return when (field) {
        PField.DiscAmt -> n.copy(
            discPct = if (gross > 0) round(n.discAmt * 100.0 / gross * 10000.0) / 10000.0 else 0.0,
        )
        PField.DiscPct, PField.Rate, PField.Qty -> n.copy(discAmt = pR2(gross * n.discPct / 100.0))
        else -> n
    }
}

private fun pValidate(l: PurchaseLineDraft): String? = when {
    l.productName.isBlank() -> "Product name is required."
    l.pack.isBlank() -> "Pack size is required."
    l.qty <= 0 -> "Quantity must be greater than 0."
    l.upp <= 0 -> "Units per pack must be greater than 0."
    l.hsn.isNotBlank() && !PHsnRegex.matches(l.hsn) -> "HSN must be 4 to 8 digits."
    l.batch.isBlank() -> "Batch number is required."
    l.expiry.isBlank() -> "Expiry date is required."
    l.mrp <= 0 -> "MRP must be greater than 0."
    l.rate <= 0 -> "Purchase rate must be greater than 0."
    l.rate > l.mrp -> "Purchase rate cannot be greater than MRP."
    l.gst !in PGstOptions -> "Select a valid GST % (0, 5, 12, 18, 28)."
    else -> null
}

/** Accepts yyyy-MM, yyyy-MM-dd, MM/yy, MM/yyyy → yyyy-MM. */
internal fun pExpiryYm(raw: String): String = parseExpiryYm(raw)

internal fun pExpiryDisplay(ym: String): String = formatExpiryMmYy(ym)

/** @deprecated Prefer [ExpiryMmYyFormField] / visual transformation — string rewrite jumps the caret. */
internal fun pExpiryTyping(typed: String): String {
    val d = typed.filter { it.isDigit() }.take(4)
    return if (d.length > 2) d.take(2) + "/" + d.drop(2) else d
}

internal fun pPackKey(v: String): String =
    v.lowercase().replace(Regex("\\s*[x×*&]\\s*"), "x").replace(Regex("\\s+"), "")

/** Same rules as backend PackSize: 60ml = 1 unit, 1x10 = 10 units. */
internal fun pUnitsFromPack(pack: String): Int {
    val s = pack.trim().lowercase().replace(Regex("\\s+"), "")
    if (s.isEmpty()) return 1
    if (Regex("^\\d+(?:\\.\\d+)?(ml|l|gm|g|mg|mcg|kg|lit)$").matches(s)) return 1
    Regex("^(\\d+)x\\d+(?:\\.\\d+)?(ml|l|gm|g|mg|mcg|kg|lit)$").find(s)?.let {
        return max(1, it.groupValues[1].toInt())
    }
    Regex("^(\\d+)[x×*](\\d+)$").find(s)?.let {
        val a = it.groupValues[1].toInt()
        val b = it.groupValues[2].toInt()
        return max(1, if (b > 1) b else if (a > 1) a else 1)
    }
    Regex("^(\\d+)(?:'?s|tabs?|caps?|nos?|pcs?)$").find(s)?.let { return max(1, it.groupValues[1].toInt()) }
    if (Regex("^\\d+$").matches(s)) return max(1, s.toInt())
    return 1
}

internal fun pPackLabel(p: JsonObject): String = p.str("label").ifBlank { p.str("pack") }.trim()

internal fun pFindPack(options: List<JsonObject>, value: String): JsonObject? {
    val k = pPackKey(value)
    if (k.isEmpty()) return null
    return options.firstOrNull { pPackKey(it.str("pack")) == k } ?: options.firstOrNull { pPackKey(pPackLabel(it)) == k }
}

internal fun pNumText(v: Double, isInt: Boolean): String = when {
    v == 0.0 -> ""
    isInt -> v.toLong().toString()
    else -> BigDecimal.valueOf(v).stripTrailingZeros().toPlainString()
}

internal fun pCleanNum(raw: String, isInt: Boolean): String {
    if (isInt) return raw.filter { it.isDigit() }.take(7)
    val sb = StringBuilder()
    var dot = false
    raw.forEach { c ->
        if (c.isDigit()) sb.append(c)
        else if (c == '.' && !dot) { dot = true; sb.append(c) }
    }
    return sb.toString().take(12)
}

private fun pRupeesInWords(amount: Double): String {
    val n = round(amount).toLong()
    if (n <= 0) return "Zero Rupees Only"
    val ones = listOf(
        "", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten",
        "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen",
    )
    val tens = listOf("", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety")
    fun two(x: Int): String = if (x < 20) ones[x] else "${tens[x / 10]}${if (x % 10 > 0) " " + ones[x % 10] else ""}".trim()
    fun three(x: Int): String {
        val h = x / 100
        val r = x % 100
        return "${if (h > 0) ones[h] + " Hundred" else ""}${if (h > 0 && r > 0) " " else ""}${if (r > 0) two(r) else ""}".trim()
    }
    var rem = n
    val crore = (rem / 10_000_000).toInt(); rem %= 10_000_000
    val lakh = (rem / 100_000).toInt(); rem %= 100_000
    val thousand = (rem / 1000).toInt(); rem %= 1000
    val parts = listOfNotNull(
        crore.takeIf { it > 0 }?.let { "${three(it)} Crore" },
        lakh.takeIf { it > 0 }?.let { "${three(it)} Lakh" },
        thousand.takeIf { it > 0 }?.let { "${three(it)} Thousand" },
        rem.toInt().takeIf { it > 0 }?.let { three(it) },
    )
    return "${parts.joinToString(" ")} Rupees Only"
}

private fun pCell(
    key: String,
    content: @Composable (Modifier) -> Unit,
): Pair<String, @Composable (Modifier) -> Unit> = key to content

private fun pPoNo(r: JsonObject): String = r.str("po_no").trim().ifBlank { r.jInt("id").toString() }

private fun JsonArray.objects(): List<JsonObject> = mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }

private fun openCachedFile(context: Context, bytes: ByteArray, fileName: String, mime: String): String? =
    runCatching {
        val file = File(context.cacheDir, fileName)
        file.writeBytes(bytes)
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
        context.startActivity(
            Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, mime)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            },
        )
    }.exceptionOrNull()?.message

internal fun shareCsv(context: Context, csv: String, fileName: String, title: String = "Export purchase report"): String? =
    runCatching {
        val file = File(context.cacheDir, fileName)
        file.writeText(csv)
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
        val send = Intent(Intent.ACTION_SEND).apply {
            type = "text/csv"
            putExtra(Intent.EXTRA_STREAM, uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        context.startActivity(Intent.createChooser(send, title))
    }.exceptionOrNull()?.message

// ───────────────────────── Purchase log ─────────────────────────

private object PurchaseLogCols {
    val po = 60.dp
    val invoice = 92.dp
    val date = 76.dp
    val distributor = 132.dp
    val cd = 52.dp
    val net = 78.dp
    val disc = 66.dp
    val tax = 66.dp
    val paid = 70.dp
    val balance = 74.dp
    val action = 34.dp
    val gap = 4.dp
}

@Composable
fun PurchaseLogScreen(
    vm: AppViewModel,
    onNewPurchase: () -> Unit,
    onEditPurchase: (Int) -> Unit,
    onBack: () -> Unit,
    onImport: () -> Unit = {},
) {
    val repo = remember { vm.purchases() }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val canEdit = vm.canEdit("PURCHASE")
    val canDelete = vm.canDelete("PURCHASE")
    val canImport = vm.canEdit("IMPORT_BILL")

    var month by remember { mutableStateOf(YearMonth.now().toString()) }
    var searchDraft by remember { mutableStateOf("") }
    var search by remember { mutableStateOf("") }
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var viewRow by remember { mutableStateOf<JsonObject?>(null) }
    var viewLoading by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf<JsonObject?>(null) }
    var deletingId by remember { mutableIntStateOf(0) }

    fun reload() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                withContext(Dispatchers.IO) { repo.list(search = search, month = month, perPage = 200) }
            }.onSuccess { rows = it.rows }.onFailure { error = it.message ?: "Could not load purchases." }
            loading = false
        }
    }

    fun openView(id: Int) {
        viewLoading = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.get(id) } }
                .onSuccess { viewRow = it }
                .onFailure { error = it.message ?: "Could not load purchase." }
            viewLoading = false
        }
    }

    fun delete(row: JsonObject) {
        val id = row.jInt("id")
        deletingId = id
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.delete(id) } }
                .onSuccess {
                    toast = "Purchase ${pPoNo(row)} deleted."
                    if (viewRow?.jInt("id") == id) viewRow = null
                    reload()
                }
                .onFailure { error = it.message ?: "Could not delete purchase." }
            deletingId = 0
        }
    }

    fun barcodes(id: Int, label: Boolean) {
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) { if (label) repo.labelBarcodesPdf(id) else repo.barcodesPdf(id) }
            }.onSuccess { bytes ->
                openCachedFile(
                    context,
                    bytes,
                    if (label) "purchase-$id-labels.pdf" else "purchase-$id-barcodes.pdf",
                    "application/pdf",
                )?.let { error = it }
            }.onFailure {
                error = it.message ?: if (label) "Could not generate label barcodes." else "Could not generate A4 barcodes."
            }
        }
    }

    fun exportCsv() {
        val header = listOf("PO No", "Invoice No", "Invoice Date", "Distributor", "Cash/Credit", "Net Total", "Discount", "Tax", "Paid", "Balance")
        val lines = mutableListOf(header.joinToString(","))
        rows.forEach { r ->
            lines += listOf(
                pPoNo(r),
                r.str("invoice_no"),
                r.str("invoice_date").take(10),
                r.str("supplier_name"),
                r.str("cash_credit"),
                "%.2f".format(r.amount("net_amount")),
                "%.2f".format(r.amount("discount_amount")),
                "%.2f".format(r.amount("gst_amount")),
                "%.2f".format(r.amount("paid")),
                "%.2f".format(r.amount("balance")),
            ).joinToString(",") { "\"" + it.replace("\"", "\"\"") + "\"" }
        }
        shareCsv(context, lines.joinToString("\n"), "purchase-report-${month.ifBlank { "all" }}.csv")?.let { error = it }
    }

    LaunchedEffect(month, search) { reload() }

    val kpis = remember(rows) {
        var net = 0.0; var discount = 0.0; var tax = 0.0; var paid = 0.0; var balance = 0.0
        rows.forEach { r ->
            net += r.amount("net_amount")
            discount += r.amount("discount_amount")
            tax += r.amount("gst_amount")
            paid += r.amount("paid")
            balance += r.amount("balance")
        }
        listOf(
            "Purchases" to rows.size.toString(),
            "Net total" to formatAmount2(net),
            "Discount" to formatAmount2(discount),
            "Tax" to formatAmount2(tax),
            "Paid" to formatAmount2(paid),
            "Balance" to formatAmount2(balance),
        )
    }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            PharmaOutlinedButton("Back", onClick = onBack, modifier = Modifier.heightIn(max = 34.dp))
            Column(Modifier.padding(start = 6.dp).weight(1f)) {
                Text("Purchase report", style = MaterialTheme.typography.titleSmall)
                Text("Supplier invoices & goods received", style = MaterialTheme.typography.labelSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            if (canImport) {
                IconButton(onClick = onImport, modifier = Modifier.size(34.dp)) {
                    Icon(Icons.Default.UploadFile, contentDescription = "Import bill", tint = PharmaSlate, modifier = Modifier.size(20.dp))
                }
            }
            if (canEdit) {
                PharmaPrimaryButton("New", modifier = Modifier.heightIn(max = 34.dp), onClick = onNewPurchase)
            }
        }
        Spacer(Modifier.height(4.dp))
        PurchaseMonthBar(
            month = month,
            onMonth = { month = it },
            onExport = { exportCsv() },
            exportEnabled = rows.isNotEmpty(),
        )
        Spacer(Modifier.height(4.dp))
        SalesSearchRow(
            value = searchDraft,
            onValueChange = { searchDraft = it },
            placeholder = "Distributor, invoice, PO, product, batch…",
            onSearch = {
                val s = searchDraft.trim()
                if (s == search) reload() else search = s
            },
            leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
        )
        toast?.let {
            Spacer(Modifier.height(4.dp))
            Surface(color = Color(0xFFE0F2FE), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                Row(Modifier.padding(horizontal = 8.dp, vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(it, Modifier.weight(1f), style = MaterialTheme.typography.labelSmall)
                    TextButton(onClick = { toast = null }) { Text("OK", style = MaterialTheme.typography.labelSmall) }
                }
            }
        }
        error?.let {
            Spacer(Modifier.height(4.dp))
            ErrorBanner(it) { error = null }
        }
        Spacer(Modifier.height(4.dp))
        PurchaseKpiGrid(kpis)
        Spacer(Modifier.height(4.dp))
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                loading -> LoadingBlock()
                rows.isEmpty() -> PharmaCard {
                    Column(Modifier.fillMaxWidth().padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(
                            if (month.isNotBlank()) "No purchases for ${pMonthLabel(month)}." else "No purchases found.",
                            style = MaterialTheme.typography.bodySmall,
                            color = PharmaMuted,
                        )
                        if (month.isNotBlank()) {
                            TextButton(onClick = { month = "" }) { Text("Show all months", style = MaterialTheme.typography.labelSmall) }
                        }
                    }
                }
                else -> PurchaseLogTable(
                    rows = rows,
                    canEdit = canEdit,
                    canDelete = canDelete,
                    deletingId = deletingId,
                    onView = { openView(it) },
                    onEdit = onEditPurchase,
                    onDelete = { confirmDelete = it },
                    modifier = Modifier.fillMaxSize(),
                )
            }
        }
    }

    if (viewLoading) LoadingBlock("Loading purchase…")
    viewRow?.let { bill ->
        PurchaseDetailSheet(
            bill = bill,
            canEdit = canEdit,
            canDelete = canDelete,
            onEdit = {
                viewRow = null
                onEditPurchase(bill.jInt("id"))
            },
            onDelete = { confirmDelete = bill },
            onBarcodes = { barcodes(bill.jInt("id"), label = false) },
            onLabels = { barcodes(bill.jInt("id"), label = true) },
            onDismiss = { viewRow = null },
        )
    }
    confirmDelete?.let { row ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            icon = { Icon(Icons.Default.Delete, null, tint = MaterialTheme.colorScheme.error) },
            title = { Text("Delete purchase ${pPoNo(row)}?", style = MaterialTheme.typography.titleSmall) },
            text = {
                Text(
                    "Invoice ${row.str("invoice_no")} · ${row.str("supplier_name")}\nStock will be reversed if items were not sold.",
                    style = MaterialTheme.typography.bodySmall,
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = null
                    delete(row)
                }) { Text("Delete", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = null }) { Text("Cancel") } },
        )
    }
}

private fun pMonthLabel(ym: String): String =
    runCatching { YearMonth.parse(ym).format(DateTimeFormatter.ofPattern("MMM yyyy")) }.getOrDefault(ym)

@Composable
private fun PurchaseMonthBar(month: String, onMonth: (String) -> Unit, onExport: () -> Unit, exportEnabled: Boolean) {
    val ym = runCatching { YearMonth.parse(month) }.getOrNull()
    Row(
        Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Row(
            Modifier
                .weight(1f)
                .height(SalesDenseFieldHeight)
                .clip(RoundedCornerShape(6.dp))
                .background(Color.White)
                .border(1.dp, PFieldBorder, RoundedCornerShape(6.dp)),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(
                onClick = { onMonth((ym ?: YearMonth.now()).minusMonths(1).toString()) },
                modifier = Modifier.size(SalesDenseFieldHeight),
            ) { Icon(Icons.Default.ChevronLeft, "Previous month", Modifier.size(18.dp), tint = PharmaSlate) }
            Text(
                if (ym != null) pMonthLabel(month) else "All months",
                Modifier.weight(1f),
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = PharmaSlate,
                textAlign = TextAlign.Center,
                maxLines = 1,
            )
            IconButton(
                onClick = { onMonth((ym ?: YearMonth.now()).plusMonths(1).toString()) },
                modifier = Modifier.size(SalesDenseFieldHeight),
            ) { Icon(Icons.Default.ChevronRight, "Next month", Modifier.size(18.dp), tint = PharmaSlate) }
        }
        PSmallChip("All", selected = month.isBlank()) { onMonth("") }
        PSmallChip("This month", selected = month == YearMonth.now().toString()) { onMonth(YearMonth.now().toString()) }
        IconButton(onClick = onExport, enabled = exportEnabled, modifier = Modifier.size(SalesDenseFieldHeight)) {
            Icon(
                Icons.Default.FileDownload,
                contentDescription = "Export CSV",
                modifier = Modifier.size(18.dp),
                tint = if (exportEnabled) Color(0xFF0284C7) else PharmaMuted,
            )
        }
    }
}

@Composable
internal fun PSmallChip(text: String, selected: Boolean, onClick: () -> Unit) {
    Box(
        Modifier
            .height(SalesDenseFieldHeight)
            .clip(RoundedCornerShape(6.dp))
            .background(if (selected) PharmaTeal else Color.White)
            .border(1.dp, if (selected) PharmaTeal else PFieldBorder, RoundedCornerShape(6.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 8.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            text,
            style = MaterialTheme.typography.labelSmall,
            color = if (selected) Color.White else PharmaSlate,
            maxLines = 1,
        )
    }
}

@Composable
private fun PurchaseKpiGrid(kpis: List<Pair<String, String>>) {
    val wide = LocalConfiguration.current.screenWidthDp >= 600
    val perRow = if (wide) 6 else 3
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        kpis.chunked(perRow).forEach { chunk ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                chunk.forEach { (label, value) -> SalesLogKpiChip(label, value, Modifier.weight(1f)) }
            }
        }
    }
}

@Composable
private fun PurchaseLogTable(
    rows: List<JsonObject>,
    canEdit: Boolean,
    canDelete: Boolean,
    deletingId: Int,
    onView: (Int) -> Unit,
    onEdit: (Int) -> Unit,
    onDelete: (JsonObject) -> Unit,
    modifier: Modifier = Modifier,
) {
    val scroll = rememberScrollState()
    PharmaCard(modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            Row(
                Modifier.fillMaxWidth().horizontalScroll(scroll).background(BillGridHeaderBg),
                horizontalArrangement = Arrangement.spacedBy(PurchaseLogCols.gap),
            ) {
                BillGridTh("PO", PurchaseLogCols.po)
                BillGridTh("INVOICE", PurchaseLogCols.invoice)
                BillGridTh("DATE", PurchaseLogCols.date)
                BillGridTh("DISTRIBUTOR", PurchaseLogCols.distributor)
                BillGridTh("C/D", PurchaseLogCols.cd)
                BillGridTh("NET", PurchaseLogCols.net, alignEnd = true)
                BillGridTh("DISC", PurchaseLogCols.disc, alignEnd = true)
                BillGridTh("TAX", PurchaseLogCols.tax, alignEnd = true)
                BillGridTh("PAID", PurchaseLogCols.paid, alignEnd = true)
                BillGridTh("BALANCE", PurchaseLogCols.balance, alignEnd = true)
                BillGridTh("VIEW", PurchaseLogCols.action)
                BillGridTh("EDIT", PurchaseLogCols.action)
                BillGridTh("DEL", PurchaseLogCols.action)
            }
            HorizontalDivider(color = PharmaBorder)
            LazyColumn(Modifier.weight(1f)) {
                items(rows.size) { index ->
                    val r = rows[index]
                    val id = r.jInt("id")
                    val balance = r.amount("balance")
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .horizontalScroll(scroll)
                            .heightIn(min = 38.dp)
                            .clickable { onView(id) }
                            .padding(vertical = 2.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(PurchaseLogCols.gap),
                    ) {
                        BillGridCellText(pPoNo(r), PurchaseLogCols.po, fontWeight = FontWeight.SemiBold)
                        BillGridCellText(r.str("invoice_no"), PurchaseLogCols.invoice)
                        BillGridCellText(formatSaleDate(r.str("invoice_date")), PurchaseLogCols.date)
                        BillGridCellText(r.str("supplier_name").ifBlank { "—" }, PurchaseLogCols.distributor)
                        PBadgeCell(r.str("cash_credit").ifBlank { "—" }, PurchaseLogCols.cd)
                        BillGridCellText(formatAmount2(r.amount("net_amount")), PurchaseLogCols.net, alignEnd = true, fontWeight = FontWeight.SemiBold)
                        BillGridCellText(formatAmount2(r.amount("discount_amount")), PurchaseLogCols.disc, alignEnd = true)
                        BillGridCellText(formatAmount2(r.amount("gst_amount")), PurchaseLogCols.tax, alignEnd = true)
                        BillGridCellText(formatAmount2(r.amount("paid")), PurchaseLogCols.paid, alignEnd = true)
                        BillGridCellText(
                            formatAmount2(balance),
                            PurchaseLogCols.balance,
                            alignEnd = true,
                            color = if (balance > 0) MaterialTheme.colorScheme.error else Color(0xFF059669),
                        )
                        SalesLogIconAction(onClick = { onView(id) }, contentDescription = "View", modifier = Modifier.width(PurchaseLogCols.action)) {
                            Icon(Icons.Default.Visibility, null, Modifier.size(18.dp), tint = Color(0xFF059669))
                        }
                        Box(Modifier.width(PurchaseLogCols.action), contentAlignment = Alignment.Center) {
                            if (canEdit) {
                                SalesLogIconAction(onClick = { onEdit(id) }, contentDescription = "Edit") {
                                    Icon(Icons.Default.Edit, null, Modifier.size(17.dp), tint = Color(0xFF0284C7))
                                }
                            } else {
                                Text("—", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            }
                        }
                        Box(Modifier.width(PurchaseLogCols.action), contentAlignment = Alignment.Center) {
                            when {
                                deletingId == id -> CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                                canDelete -> SalesLogIconAction(onClick = { onDelete(r) }, contentDescription = "Delete") {
                                    Icon(Icons.Default.Delete, null, Modifier.size(17.dp), tint = MaterialTheme.colorScheme.error)
                                }
                                else -> Text("—", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            }
                        }
                    }
                    if (index < rows.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.45f))
                }
            }
        }
    }
}

@Composable
private fun PBadgeCell(text: String, width: Dp) {
    Box(Modifier.width(width).padding(horizontal = 2.dp)) {
        Text(
            text,
            Modifier
                .clip(RoundedCornerShape(4.dp))
                .background(PReadBg)
                .padding(horizontal = 5.dp, vertical = 1.dp),
            style = MaterialTheme.typography.labelSmall,
            color = PharmaSlate,
            maxLines = 1,
        )
    }
}

// ───────────────────────── Purchase view (bottom sheet) ─────────────────────────

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PurchaseDetailSheet(
    bill: JsonObject,
    canEdit: Boolean,
    canDelete: Boolean,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
    onBarcodes: () -> Unit,
    onLabels: () -> Unit,
    onDismiss: () -> Unit,
) {
    val items = bill.arr("items").objects()
    val headScroll = rememberScrollState()
    val lineScroll = rememberScrollState()
    val balance = bill.amount("balance")
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = 12.dp)
                .verticalScroll(rememberScrollState()),
        ) {
            Text("Purchase ${pPoNo(bill)}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
            Text(
                "${bill.str("supplier_name").ifBlank { "—" }} · Invoice ${bill.str("invoice_no")}",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
            Spacer(Modifier.height(8.dp))
            BillLinesFrame {
                Row(Modifier.horizontalScroll(headScroll)) {
                    Column {
                        Row(Modifier.background(BillGridHeaderBg), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                            BillGridTh("INVOICE", 84.dp)
                            BillGridTh("DATE", 76.dp)
                            BillGridTh("DISTRIBUTOR", 120.dp)
                            BillGridTh("TOTAL DISC", 72.dp, alignEnd = true)
                            BillGridTh("GROSS", 76.dp, alignEnd = true)
                            BillGridTh("GST", 68.dp, alignEnd = true)
                            BillGridTh("NET", 80.dp, alignEnd = true)
                            BillGridTh("PAYMENT", 60.dp)
                            BillGridTh("PAID", 72.dp, alignEnd = true)
                            BillGridTh("BALANCE", 76.dp, alignEnd = true)
                        }
                        HorizontalDivider(color = PharmaBorder)
                        Row(Modifier.padding(vertical = 3.dp), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                            BillGridCellText(bill.str("invoice_no"), 84.dp)
                            BillGridCellText(formatSaleDate(bill.str("invoice_date")), 76.dp)
                            BillGridCellText(bill.str("supplier_name").ifBlank { "—" }, 120.dp)
                            BillGridCellText(formatAmount2(bill.amount("discount_amount")), 72.dp, alignEnd = true)
                            BillGridCellText(formatAmount2(bill.amount("gross_amount")), 76.dp, alignEnd = true)
                            BillGridCellText(formatAmount2(bill.amount("gst_amount")), 68.dp, alignEnd = true)
                            BillGridCellText(formatAmount2(bill.amount("net_amount")), 80.dp, alignEnd = true, fontWeight = FontWeight.SemiBold)
                            BillGridCellText(bill.str("cash_credit").ifBlank { "—" }, 60.dp)
                            BillGridCellText(formatAmount2(bill.amount("paid")), 72.dp, alignEnd = true)
                            BillGridCellText(
                                formatAmount2(balance),
                                76.dp,
                                alignEnd = true,
                                color = if (balance > 0) MaterialTheme.colorScheme.error else Color.Unspecified,
                            )
                        }
                    }
                }
            }
            Spacer(Modifier.height(8.dp))
            Text("Items (${items.size})", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            Spacer(Modifier.height(2.dp))
            BillLinesFrame {
                Row(Modifier.horizontalScroll(lineScroll)) {
                    Column {
                        Row(Modifier.background(BillGridHeaderBg), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                            BillGridTh("HSN", 60.dp)
                            BillGridTh("PRODUCT", 140.dp)
                            BillGridTh("BATCH", 72.dp)
                            BillGridTh("EXP", 46.dp)
                            BillGridTh("P QTY", 44.dp, alignEnd = true)
                            BillGridTh("F QTY", 44.dp, alignEnd = true)
                            BillGridTh("T QTY", 44.dp, alignEnd = true)
                            BillGridTh("RATE", 60.dp, alignEnd = true)
                            BillGridTh("PTR", 60.dp, alignEnd = true)
                            BillGridTh("DISC%", 48.dp, alignEnd = true)
                            BillGridTh("GST%", 44.dp, alignEnd = true)
                            BillGridTh("MRP", 60.dp, alignEnd = true)
                            BillGridTh("PACK", 60.dp)
                            BillGridTh("NET", 72.dp, alignEnd = true)
                        }
                        HorizontalDivider(color = PharmaBorder)
                        if (items.isEmpty()) {
                            Text("No line items.", Modifier.padding(12.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                        }
                        items.forEachIndexed { i, it ->
                            Row(Modifier.padding(vertical = 3.dp), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                BillGridCellText(it.str("hsn_code").ifBlank { "—" }, 60.dp)
                                BillGridCellText(it.str("product_name"), 140.dp, fontWeight = FontWeight.SemiBold)
                                BillGridCellText(it.str("batch_no").ifBlank { "—" }, 72.dp)
                                BillGridCellText(pExpiryDisplay(pExpiryYm(it.str("expiry_date"))).ifBlank { "—" }, 46.dp)
                                BillGridCellText(it.jInt("quantity").toString(), 44.dp, alignEnd = true)
                                BillGridCellText(it.jInt("free_quantity").toString(), 44.dp, alignEnd = true)
                                BillGridCellText((it.jInt("quantity") + it.jInt("free_quantity")).toString(), 44.dp, alignEnd = true)
                                BillGridCellText(formatAmount2(it.amount("purchase_rate")), 60.dp, alignEnd = true)
                                BillGridCellText(formatAmount2(it.amount("ptr")), 60.dp, alignEnd = true)
                                BillGridCellText(pNumText(it.amount("discount_pct"), false).ifBlank { "0" }, 48.dp, alignEnd = true)
                                BillGridCellText(pNumText(it.amount("gst_rate"), false).ifBlank { "0" }, 44.dp, alignEnd = true)
                                BillGridCellText(formatAmount2(it.amount("mrp")), 60.dp, alignEnd = true)
                                BillGridCellText(it.str("pack_size").ifBlank { "—" }, 60.dp)
                                BillGridCellText(formatAmount2(it.amount("line_total")), 72.dp, alignEnd = true, fontWeight = FontWeight.SemiBold)
                            }
                            if (i < items.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.4f))
                        }
                    }
                }
            }
            Spacer(Modifier.height(6.dp))
            Text(
                buildString {
                    if (bill.str("due_date").isNotBlank()) append("Due ${formatSaleDate(bill.str("due_date"))} · ")
                    append("CD ${formatAmount2(bill.amount("cd_amount"))}")
                },
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
            Spacer(Modifier.height(8.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                OutlinedButton(
                    onClick = onBarcodes,
                    modifier = Modifier.weight(1f).height(36.dp),
                    shape = RoundedCornerShape(8.dp),
                    contentPadding = PaddingValues(horizontal = 6.dp),
                ) {
                    Icon(Icons.Default.Print, null, Modifier.size(16.dp))
                    Spacer(Modifier.width(4.dp))
                    Text("A4 barcodes", style = MaterialTheme.typography.labelSmall, maxLines = 1)
                }
                OutlinedButton(
                    onClick = onLabels,
                    modifier = Modifier.weight(1f).height(36.dp),
                    shape = RoundedCornerShape(8.dp),
                    contentPadding = PaddingValues(horizontal = 6.dp),
                ) {
                    Icon(Icons.Default.LocalOffer, null, Modifier.size(16.dp))
                    Spacer(Modifier.width(4.dp))
                    Text("Label barcodes", style = MaterialTheme.typography.labelSmall, maxLines = 1)
                }
            }
            Spacer(Modifier.height(6.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                if (canDelete) {
                    OutlinedButton(
                        onClick = onDelete,
                        modifier = Modifier.weight(1f).height(36.dp),
                        shape = RoundedCornerShape(8.dp),
                        colors = ButtonDefaults.outlinedButtonColors(contentColor = MaterialTheme.colorScheme.error),
                    ) {
                        Icon(Icons.Default.Delete, null, Modifier.size(16.dp))
                        Spacer(Modifier.width(4.dp))
                        Text("Delete", style = MaterialTheme.typography.labelSmall)
                    }
                }
                if (canEdit) {
                    OutlinedButton(
                        onClick = onEdit,
                        modifier = Modifier.weight(1f).height(36.dp),
                        shape = RoundedCornerShape(8.dp),
                    ) {
                        Icon(Icons.Default.Edit, null, Modifier.size(16.dp))
                        Spacer(Modifier.width(4.dp))
                        Text("Edit", style = MaterialTheme.typography.labelSmall)
                    }
                }
                Button(
                    onClick = onDismiss,
                    modifier = Modifier.weight(1f).height(36.dp),
                    shape = RoundedCornerShape(8.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal),
                ) { Text("Close", style = MaterialTheme.typography.labelSmall) }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

// ───────────────────────── Purchase entry / edit ─────────────────────────

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun PurchaseEntryScreen(vm: AppViewModel, editId: Int?, onBack: () -> Unit, onSaved: () -> Unit) {
    val repo = remember { vm.purchases() }
    val scope = rememberCoroutineScope()
    val focusManager = LocalFocusManager.current
    val config = LocalConfiguration.current
    val isEdit = editId != null
    val today = remember { LocalDate.now() }

    var loading by remember { mutableStateOf(isEdit) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }

    // Header
    var poNo by remember { mutableStateOf("") }
    var invoiceNo by remember { mutableStateOf("") }
    var invoiceWarn by remember { mutableStateOf<String?>(null) }
    var invoiceDate by remember { mutableStateOf(today.toString()) }
    var supplierId by remember { mutableIntStateOf(0) }
    var supplierName by remember { mutableStateOf("") }
    var distributors by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var distributorsLoading by remember { mutableStateOf(true) }
    var supplierSheet by remember { mutableStateOf(false) }
    var cashCredit by remember { mutableStateOf("Credit") }
    var dueDate by remember { mutableStateOf(today.plusDays(45).toString()) }
    var cdMode by remember { mutableStateOf("Percentage") }
    var cdPct by remember { mutableDoubleStateOf(0.0) }
    var cdAmt by remember { mutableDoubleStateOf(0.0) }
    var netBillAmt by remember { mutableDoubleStateOf(0.0) }
    var transport by remember { mutableDoubleStateOf(0.0) }
    var transportGst by remember { mutableDoubleStateOf(0.0) }
    var debitNotes by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var debitNoteNo by remember { mutableStateOf("") }
    var debitAmt by remember { mutableDoubleStateOf(0.0) }
    var headerExpanded by remember { mutableStateOf(true) }
    var addExpanded by remember { mutableStateOf(true) }

    // Product entry
    var entry by remember { mutableStateOf(PurchaseLineDraft()) }
    var entryExpiryText by remember { mutableStateOf("") }
    var productQuery by remember { mutableStateOf("") }
    var productHits by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var productSearching by remember { mutableStateOf(false) }
    var productListOpen by remember { mutableStateOf(false) }
    var packOptions by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    /** -1 = add-product form, otherwise the line key whose pack is being edited. */
    var packSheetFor by remember { mutableStateOf<Long?>(null) }
    var lines by remember { mutableStateOf<List<PurchaseLineDraft>>(emptyList()) }

    // Sold-stock edit confirmation (web parity)
    var soldLines by remember { mutableStateOf<List<JsonObject>?>(null) }
    var soldBlocked by remember { mutableStateOf(false) }

    // Quick-add distributor / product (web Add in Master)
    var quickMaster by remember { mutableStateOf<String?>(null) }
    var quickName by remember { mutableStateOf("") }
    val canQuickAddMaster = vm.canEdit("MASTERS") || vm.hasFullAccess()

    fun loadDebitNotes(party: String, keepNo: String = "", keepAmt: Double = 0.0) {
        debitNoteNo = keepNo
        debitAmt = keepAmt
        debitNotes = emptyList()
        if (party.isBlank()) return
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.openDebitNotes(party) } }
                .onSuccess { debitNotes = it }
        }
    }

    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { repo.packSizes() } }.onSuccess { packOptions = it }
    }
    LaunchedEffect(Unit) {
        try {
            runCatching { withContext(Dispatchers.IO) { repo.allDistributors() } }
                .onSuccess { distributors = it }
                .onFailure { error = it.message ?: "Could not load distributors." }
        } finally {
            distributorsLoading = false
        }
    }
    LaunchedEffect(editId) {
        if (editId == null) return@LaunchedEffect
        loading = true
        try {
            runCatching { withContext(Dispatchers.IO) { repo.get(editId) } }
                .onSuccess { p ->
                    poNo = p.str("po_no").ifBlank { p.jInt("id").toString() }
                    invoiceNo = p.str("invoice_no")
                    invoiceDate = p.str("invoice_date").take(10).ifBlank { today.toString() }
                    supplierId = p.jInt("supplier_id")
                    supplierName = p.str("supplier_name")
                    cashCredit = p.str("cash_credit").takeIf { it in PPayModes } ?: "Credit"
                    dueDate = p.str("due_date").take(10).ifBlank { today.plusDays(45).toString() }
                    cdMode = if (p.str("cd_mode") == "Amount") "Amount" else "Percentage"
                    cdPct = p.amount("cd_pct")
                    cdAmt = p.amount("cd_amount")
                    transport = p.amount("transport_charge")
                    transportGst = p.amount("transport_gst_pct")
                    lines = p.arr("items").objects().map { it ->
                        val qty = it.jInt("quantity")
                        val rate = it.amount("purchase_rate")
                        val pct = it.amount("discount_pct")
                        PurchaseLineDraft(
                            bindingId = it.jInt("product_binding_id").takeIf { id -> id > 0 },
                            productName = it.str("product_name"),
                            category = "Allopathy",
                            hsn = it.str("hsn_code"),
                            batch = it.str("batch_no"),
                            expiry = pExpiryYm(it.str("expiry_date")),
                            shelf = it.str("shelf_location"),
                            pack = it.str("pack_size"),
                            qty = qty,
                            upp = it.jInt("units_per_pack").coerceAtLeast(1),
                            free = it.jInt("free_quantity"),
                            rate = rate,
                            mrp = it.amount("mrp"),
                            ptr = it.amount("ptr"),
                            pts = it.amount("pts"),
                            gst = it.amount("gst_rate").takeIf { g -> g > 0 } ?: 12.0,
                            discPct = pct,
                            discAmt = pR2(qty * rate * pct / 100.0),
                            schemePct = it.amount("scheme_pct"),
                        )
                    }
                    headerExpanded = false
                    addExpanded = false
                    loadDebitNotes(supplierName, p.str("debit_note_no"), p.amount("debit_deduction_amt"))
                }
                .onFailure { error = it.message ?: "Could not load purchase for edit." }
        } finally {
            loading = false
        }
    }

    // Duplicate invoice check (new purchases only)
    LaunchedEffect(invoiceNo) {
        invoiceWarn = null
        val inv = invoiceNo.trim()
        if (isEdit || inv.isEmpty()) return@LaunchedEffect
        delay(400)
        runCatching { withContext(Dispatchers.IO) { repo.checkInvoice(inv) } }.onSuccess { r ->
            if (r.bool("exists")) {
                val po = r.obj("purchase").let { it.str("po_no").ifBlank { it.str("id") } }
                invoiceWarn = "Invoice already exists${if (po.isNotBlank()) " (PO $po)" else ""}."
            }
        }
    }

    // Live product search
    LaunchedEffect(productQuery, productListOpen) {
        if (!productListOpen) return@LaunchedEffect
        productSearching = true
        try {
            delay(220)
            runCatching { withContext(Dispatchers.IO) { repo.searchProducts(productQuery.trim()) } }
                .onSuccess { productHits = it }
        } finally {
            productSearching = false
        }
    }

    // Auto PTR / PTS from MRP + GST + category margin
    LaunchedEffect(entry.mrp, entry.gst, entry.category) {
        if (entry.mrp <= 0) return@LaunchedEffect
        delay(250)
        runCatching {
            withContext(Dispatchers.IO) { repo.computeRates(entry.mrp, entry.gst, entry.category) }
        }.onSuccess { r -> entry = entry.copy(ptr = r.amount("ptr"), pts = r.amount("pts")) }
    }

    fun patchEntry(field: PField = PField.Other, change: (PurchaseLineDraft) -> PurchaseLineDraft) {
        entry = pSync(change(entry), field)
    }

    fun updateLine(key: Long, field: PField = PField.Other, change: (PurchaseLineDraft) -> PurchaseLineDraft) {
        lines = lines.map { if (it.key == key) pSync(change(it), field) else it }
    }

    fun pickSupplier(s: JsonObject) {
        supplierId = s.jInt("id")
        supplierName = s.str("name").uppercase()
        supplierSheet = false
        loadDebitNotes(supplierName)
    }

    fun selectProduct(p: JsonObject) {
        val pack = p.str("pack_size").trim()
        val match = pFindPack(packOptions, pack)
        val units = match?.jInt("units")?.takeIf { it > 0 } ?: pUnitsFromPack(pack)
        entry = entry.copy(
            masterProductId = p.jInt("id"),
            productName = p.str("name").uppercase(),
            schedule = p.str("schedule_category").ifBlank { "OTC" },
            category = p.str("product_category"),
            hsn = p.str("hsn_code"),
            gst = p.amount("default_gst_rate").takeIf { it in PGstOptions && it > 0 } ?: 12.0,
            upp = units,
            pack = match?.str("pack")?.ifBlank { pack } ?: pack,
            shelf = p.str("shelf_location"),
            mrp = p.amount("mrp"),
            ptr = p.amount("ptr"),
            pts = p.amount("pts"),
        )
        productQuery = p.str("name").uppercase()
        productHits = emptyList()
        productListOpen = false
        focusManager.clearFocus()
    }

    fun addLine() {
        val e = entry.copy(expiry = pExpiryYm(entryExpiryText))
        val err = pValidate(e)
        if (err != null) {
            error = err
            return
        }
        error = null
        lines = lines + e.copy(key = System.nanoTime())
        entry = PurchaseLineDraft(gst = e.gst)
        entryExpiryText = ""
        productQuery = ""
        productListOpen = false
        focusManager.clearFocus()
        toast = "${e.productName} added."
    }

    fun applyPack(target: Long, pack: String, units: Int?) {
        val upp = units?.takeIf { it > 0 } ?: pUnitsFromPack(pack)
        if (target == -1L) entry = entry.copy(pack = pack, upp = upp)
        else updateLine(target) { it.copy(pack = pack, upp = upp) }
    }

    fun onCashCredit(mode: String) {
        cashCredit = mode
        when {
            mode == "Cash" -> dueDate = invoiceDate
            mode != "Credit" && dueDate.isBlank() -> dueDate = invoiceDate
            mode == "Credit" && dueDate.isBlank() -> dueDate = today.plusDays(45).toString()
        }
    }

    // Totals (web purchase-entry parity)
    val itemsTotal = lines.sumOf { pTotal(it) }
    val taxableTotal = lines.sumOf { pTaxable(it) }
    val gstTotal = lines.sumOf { pTaxable(it) * it.gst / 100.0 }
    val lineDiscTotal = lines.sumOf { pDisc(it) }
    val cdAmountComputed = if (cdMode == "Amount") max(0.0, cdAmt) else pR2(itemsTotal * cdPct / 100.0)
    val transportTotal = pR2(max(0.0, transport) * (1 + transportGst / 100.0))
    val billNetBeforeDebit = max(0.0, itemsTotal - cdAmountComputed) + transportTotal
    val netTotal = max(0.0, billNetBeforeDebit - debitAmt)

    fun recalcCdFromAmount() {
        if (cdMode != "Amount") return
        cdPct = if (netBillAmt > 0) round(cdAmt / netBillAmt * 10000.0) / 100.0 else 0.0
    }

    fun buildBody(confirmSold: Boolean): JsonObject {
        val items = JsonArray()
        lines.forEach { l ->
            items.add(
                JsonObject().apply {
                    addProperty("product_name", l.productName)
                    addProperty("hsn_code", l.hsn)
                    addProperty("batch_no", l.batch)
                    addProperty("expiry_date", if (l.expiry.length == 7) "${l.expiry}-01" else l.expiry)
                    addProperty("shelf_location", l.shelf)
                    addProperty("pack_size", l.pack)
                    addProperty("quantity", l.qty)
                    addProperty("units_per_pack", l.upp.coerceAtLeast(1))
                    addProperty("free_quantity", l.free)
                    addProperty("purchase_rate", l.rate)
                    addProperty("mrp", l.mrp)
                    addProperty("ptr", l.ptr)
                    addProperty("pts", l.pts)
                    addProperty("scheme_pct", l.schemePct)
                    addProperty("discount_pct", l.discPct)
                    addProperty("discount_amt", pDisc(l))
                    addProperty("gst_rate", l.gst)
                    l.bindingId?.let { addProperty("product_binding_id", it) }
                    when {
                        l.masterProductId != null -> addProperty("master_product_id", l.masterProductId)
                        l.bindingId == null -> add(
                            "new_product",
                            JsonObject().apply {
                                addProperty("name", l.productName)
                                addProperty("schedule_category", l.schedule)
                                addProperty("product_category", l.category)
                                addProperty("default_gst_rate", l.gst)
                                addProperty("units_per_pack", l.upp.coerceAtLeast(1))
                                addProperty("shelf_location", l.shelf)
                                addProperty("hsn_code", l.hsn)
                                addProperty("pack_size", l.pack)
                            },
                        )
                    }
                },
            )
        }
        return JsonObject().apply {
            addProperty("invoice_no", invoiceNo.trim().uppercase())
            addProperty("invoice_date", invoiceDate)
            addProperty("supplier_id", supplierId)
            addProperty("supplier_name", supplierName)
            addProperty("cash_credit", cashCredit)
            addProperty("due_date", dueDate)
            addProperty("cd_mode", cdMode)
            addProperty("cd_pct", cdPct)
            addProperty("cd_amount", cdAmountComputed)
            addProperty("transport_charge", transport)
            addProperty("transport_gst_pct", transportGst)
            if (debitNoteNo.isNotBlank()) addProperty("debit_note_no", debitNoteNo)
            addProperty("debit_deduction_amt", debitAmt)
            if (confirmSold) addProperty("confirm_sold_edit", true)
            add("items", items)
        }
    }

    fun save(confirmSold: Boolean = false) {
        if (saving) return
        val err = when {
            invoiceNo.isBlank() -> "Invoice number is required."
            invoiceWarn != null && !isEdit -> invoiceWarn
            supplierId <= 0 || supplierName.isBlank() -> "Please select a distributor (or add one in Distributor Master)."
            lines.isEmpty() -> "Add at least one product to the list."
            else -> lines.withIndex().firstNotNullOfOrNull { (i, l) -> pValidate(l)?.let { "Line ${i + 1}: $it" } }
        }
        if (err != null) {
            error = err
            if (lines.isNotEmpty() && (invoiceNo.isBlank() || supplierId <= 0)) headerExpanded = true
            return
        }
        saving = true
        error = null
        val body = buildBody(confirmSold)
        scope.launch {
            try {
                runCatching {
                    withContext(Dispatchers.IO) { if (editId != null) repo.update(editId, body) else repo.create(body) }
                }.onSuccess {
                    onSaved()
                }.onFailure { e ->
                    val ex = e as? ApiException
                    val errs = ex?.body?.obj("errors")
                    val sold = errs?.arr("sold_lines")?.objects().orEmpty()
                    when {
                        ex?.status == 409 && errs?.has("sold_edit_confirm") == true -> {
                            soldBlocked = false
                            soldLines = sold
                        }
                        ex?.status == 422 && sold.isNotEmpty() -> {
                            soldBlocked = true
                            soldLines = sold
                        }
                        else -> error = e.message ?: if (isEdit) "Could not update purchase." else "Could not save purchase."
                    }
                }
            } finally {
                saving = false
            }
        }
    }

    if (loading) {
        LoadingBlock("Loading purchase…")
        return
    }

    val gridCols = when {
        config.screenWidthDp >= 840 -> 6
        config.screenWidthDp >= 600 -> 4
        else -> 3
    }
    val listMaxHeight = (config.screenHeightDp * 0.3f).coerceIn(150f, 280f).dp
    val hitsMaxHeight = (config.screenHeightDp * 0.28f).coerceIn(140f, 240f).dp
    val imeVisible = WindowInsets.isImeVisible

    Scaffold(
        contentWindowInsets = WindowInsets(0, 0, 0, 0),
        topBar = {
            CompactFormTopBar(
                title = buildString {
                    append(if (isEdit) "Edit purchase" else "New purchase")
                    if (poNo.isNotBlank()) append(" · PO $poNo")
                },
                onClose = onBack,
            )
        },
        bottomBar = {
            if (!imeVisible) {
                PurchaseTotalsBar(
                    itemCount = lines.size,
                    taxable = taxableTotal,
                    gst = gstTotal,
                    net = netTotal,
                    saving = saving,
                    isEdit = isEdit,
                    onSave = { save() },
                )
            }
        },
    ) { padding ->
        val scrollState = rememberScrollState()
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .imePadding()
                .verticalScroll(scrollState)
                .padding(horizontal = CompactScreenPadding)
                .padding(top = CompactFormHeaderGap, bottom = 6.dp)
                .navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            error?.let { ErrorBanner(it) { error = null } }
            toast?.let { msg ->
                LaunchedEffect(msg) {
                    delay(2500)
                    if (toast == msg) toast = null
                }
                Surface(color = Color(0xFFDCFCE7), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                    Text(msg, Modifier.padding(horizontal = 10.dp, vertical = 6.dp), style = MaterialTheme.typography.labelSmall, color = Color(0xFF166534))
                }
            }

            // ── Invoice details ──
            PSection(
                title = "Invoice details",
                expanded = headerExpanded,
                onToggle = { headerExpanded = !headerExpanded },
                summary = listOf(invoiceNo, supplierName).filter { it.isNotBlank() }.joinToString(" · "),
            ) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SalesFormField(
                        label = "Invoice No *",
                        value = invoiceNo,
                        onValueChange = { invoiceNo = it.uppercase() },
                        modifier = Modifier.weight(1f),
                        isError = invoiceWarn != null,
                    )
                    PDateField(
                        label = "Invoice date *",
                        iso = invoiceDate,
                        onChange = {
                            invoiceDate = it
                            if (cashCredit == "Cash") dueDate = it
                        },
                        modifier = Modifier.weight(1f),
                    )
                }
                invoiceWarn?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.error) }
                PPickField(
                    label = "Distributor *",
                    value = supplierName,
                    placeholder = if (distributorsLoading) "Loading distributors…" else "Select distributor…",
                    onClick = { supplierSheet = true },
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    POptionDropdown("Cash/Credit *", cashCredit, PPayModes, { onCashCredit(it) }, Modifier.weight(1f))
                    PDateField(
                        label = "Due date *",
                        iso = dueDate,
                        onChange = { dueDate = it },
                        modifier = Modifier.weight(1f),
                        readOnly = cashCredit == "Cash",
                    )
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    POptionDropdown(
                        "Total bill CD",
                        cdMode,
                        listOf("Percentage", "Amount"),
                        { mode ->
                            cdMode = mode
                            if (mode == "Amount") {
                                if (netBillAmt <= 0) netBillAmt = pR2(itemsTotal)
                                recalcCdFromAmount()
                            }
                        },
                        Modifier.weight(1f),
                    )
                    if (cdMode == "Amount") {
                        PReadField("CD %", pNumText(cdPct, false).ifBlank { "0" }, Modifier.weight(1f))
                    } else {
                        PNumField("CD % *", cdPct, { cdPct = it }, Modifier.weight(1f))
                    }
                }
                if (cdMode == "Amount") {
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PNumField("Net bill", netBillAmt, { netBillAmt = it; recalcCdFromAmount() }, Modifier.weight(1f))
                        PNumField("CD amt", cdAmt, { cdAmt = it; recalcCdFromAmount() }, Modifier.weight(1f))
                    }
                }
                val debitOptions = listOf("") + debitNotes.map { it.str("note_no") }.let { nos ->
                    if (debitNoteNo.isNotBlank() && debitNoteNo !in nos) nos + debitNoteNo else nos
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    POptionDropdown(
                        label = "Return bill deduction",
                        value = debitNoteNo,
                        options = debitOptions,
                        onSelect = { no ->
                            debitNoteNo = no
                            debitAmt = debitNotes.firstOrNull { it.str("note_no") == no }?.amount("amount")
                                ?: if (no.isBlank()) 0.0 else debitAmt
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
                    PReadField("Bill net", formatAmount2(billNetBeforeDebit), Modifier.weight(1f))
                    PReadField("Net after debit", formatAmount2(netTotal), Modifier.weight(1f), highlight = true)
                }
            }

            // ── Add product ──
            PSection(
                title = "Add product",
                expanded = addExpanded,
                onToggle = { addExpanded = !addExpanded },
                summary = if (entry.productName.isNotBlank()) entry.productName else "",
            ) {
                SalesSearchRow(
                    value = productQuery,
                    onValueChange = {
                        val v = it.uppercase()
                        productQuery = v
                        entry = entry.copy(productName = v, masterProductId = null)
                        productListOpen = true
                    },
                    placeholder = "Search product…",
                    onSearch = { productListOpen = true },
                    leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                    trailingIcon = {
                        when {
                            productSearching -> CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                            productQuery.isNotEmpty() -> IconButton(
                                onClick = {
                                    productQuery = ""
                                    entry = entry.copy(productName = "", masterProductId = null)
                                },
                                modifier = Modifier.size(22.dp),
                            ) { Icon(Icons.Default.Close, "Clear", Modifier.size(14.dp), tint = PharmaMuted) }
                        }
                    },
                    onFocusChanged = { if (it.isFocused) productListOpen = true },
                )
                if (entry.productName.isNotBlank()) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        val catalog = entry.masterProductId != null
                        Text(
                            if (catalog) "CATALOG" else "NEW",
                            Modifier
                                .clip(RoundedCornerShape(4.dp))
                                .background(if (catalog) Color(0xFFDCFCE7) else Color(0xFFFEF3C7))
                                .padding(horizontal = 6.dp, vertical = 1.dp),
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = if (catalog) Color(0xFF166534) else Color(0xFF92400E),
                        )
                        Text(
                            if (catalog) "Product from catalog" else "Will be created in Product Master on save",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                        )
                    }
                }
                if (productListOpen) {
                    PProductHits(
                        hits = productHits,
                        searching = productSearching,
                        query = productQuery,
                        maxHeight = hitsMaxHeight,
                        onPick = { selectProduct(it) },
                        onClose = { productListOpen = false },
                        canAddNew = canQuickAddMaster,
                        onAddNew = {
                            quickName = productQuery.trim().ifBlank { entry.productName.trim() }
                            productListOpen = false
                            quickMaster = "products"
                        },
                    )
                }

                val cells = buildList {
                    add(pCell("hsn") { m ->
                        SalesFormField("HSN", entry.hsn, { v -> patchEntry { it.copy(hsn = v.filter(Char::isDigit).take(8)) } }, m, keyboardType = KeyboardType.Number)
                    })
                    add(pCell("cat") { m ->
                        POptionDropdown("Category", entry.category, listOf("") + PCategoryOptions, { v -> patchEntry { it.copy(category = v) } }, m)
                    })
                    if (entry.masterProductId == null) {
                        add(pCell("sched") { m ->
                            POptionDropdown("Schedule", entry.schedule, PScheduleOptions, { v -> patchEntry { it.copy(schedule = v) } }, m)
                        })
                    }
                    add(pCell("batch") { m ->
                        SalesFormField("Batch *", entry.batch, { v -> patchEntry { it.copy(batch = v.uppercase()) } }, m)
                    })
                    add(pCell("exp") { m ->
                        ExpiryMmYyFormField(
                            label = "Expiry *",
                            displayText = entryExpiryText,
                            onDisplayText = { entryExpiryText = it },
                            modifier = m,
                        )
                    })
                    add(pCell("shelf") { m ->
                        SalesFormField("Shelf", entry.shelf, { v -> patchEntry { it.copy(shelf = v.uppercase()) } }, m)
                    })
                    add(pCell("pack") { m ->
                        PPickField("Pack *", entry.pack, "Pack…", { packSheetFor = -1L }, m)
                    })
                    add(pCell("upp") { m ->
                        PNumField("U/Pk", entry.upp.toDouble(), { v -> patchEntry { it.copy(upp = v.toInt()) } }, m, isInt = true)
                    })
                    add(pCell("qty") { m ->
                        PNumField("Qty (pk) *", entry.qty.toDouble(), { v -> patchEntry(PField.Qty) { it.copy(qty = v.toInt()) } }, m, isInt = true)
                    })
                    add(pCell("free") { m ->
                        PNumField("Free", entry.free.toDouble(), { v -> patchEntry { it.copy(free = v.toInt()) } }, m, isInt = true)
                    })
                    add(pCell("sch") { m ->
                        PNumField("Sch %", entry.schemePct, { v -> patchEntry(PField.Scheme) { it.copy(schemePct = v) } }, m)
                    })
                    add(pCell("rate") { m ->
                        PNumField("Rate *", entry.rate, { v -> patchEntry(PField.Rate) { it.copy(rate = v) } }, m)
                    })
                    add(pCell("mrp") { m ->
                        PNumField("MRP *", entry.mrp, { v -> patchEntry { it.copy(mrp = v) } }, m)
                    })
                    add(pCell("ptr") { m ->
                        PNumField("PTR", entry.ptr, { v -> patchEntry { it.copy(ptr = v) } }, m)
                    })
                    add(pCell("pts") { m ->
                        PNumField("PTS", entry.pts, { v -> patchEntry { it.copy(pts = v) } }, m)
                    })
                    add(pCell("gst") { m ->
                        POptionDropdown(
                            "GST %",
                            pNumText(entry.gst, false).ifBlank { "0" },
                            PGstOptions.map { pNumText(it, false).ifBlank { "0" } },
                            { v -> patchEntry { it.copy(gst = v.toDoubleOrNull() ?: 0.0) } },
                            m,
                        )
                    })
                    add(pCell("disc") { m ->
                        PNumField("Disc %", entry.discPct, { v -> patchEntry(PField.DiscPct) { it.copy(discPct = v) } }, m)
                    })
                    add(pCell("discamt") { m ->
                        PNumField("Disc ₹", entry.discAmt, { v -> patchEntry(PField.DiscAmt) { it.copy(discAmt = v) } }, m)
                    })
                    add(pCell("netrt") { m -> PReadField("Net rate", formatAmount2(pNetRate(entry)), m) })
                    add(pCell("total") { m -> PReadField("Total", formatAmount2(pTotal(entry)), m, highlight = true) })
                }
                cells.chunked(gridCols).forEach { row ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        row.forEach { (k, cell) -> key(k) { cell(Modifier.weight(1f)) } }
                        repeat(gridCols - row.size) { Spacer(Modifier.weight(1f)) }
                    }
                }
                Button(
                    onClick = { addLine() },
                    modifier = Modifier.fillMaxWidth().height(36.dp),
                    shape = RoundedCornerShape(8.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal),
                    contentPadding = PaddingValues(horizontal = 8.dp),
                ) {
                    Icon(Icons.Default.Add, null, Modifier.size(16.dp))
                    Spacer(Modifier.width(4.dp))
                    Text("ADD TO LIST", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold)
                }
            }

            // ── Added products ──
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "Added products",
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = PharmaSlate,
                    modifier = Modifier.weight(1f),
                )
                Text("${lines.size} item(s)", style = MaterialTheme.typography.labelSmall, color = PharmaTeal)
            }
            if (lines.isEmpty()) {
                Text(
                    "No items added yet. Fill the form above and tap ADD TO LIST.",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
            } else {
                PurchaseLinesTable(
                    lines = lines,
                    maxBodyHeight = listMaxHeight,
                    onUpdate = { k, f, change -> updateLine(k, f, change) },
                    onPack = { packSheetFor = it },
                    onRemove = { k -> lines = lines.filterNot { it.key == k } },
                )
            }

            // ── Transport & totals ──
            PSection(title = "Transport & totals", expanded = true, onToggle = null, summary = "") {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    PNumField("Transport ₹", transport, { transport = it }, Modifier.weight(1f))
                    PNumField("Trans. GST %", transportGst, { transportGst = it }, Modifier.weight(1f))
                    PReadField("Total trans.", formatAmount2(transportTotal), Modifier.weight(1f))
                }
                FlowRow(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(4.dp),
                    verticalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    PTotalChip("Taxable", taxableTotal)
                    PTotalChip("GST", gstTotal)
                    PTotalChip("C.D.", cdAmountComputed)
                    PTotalChip("Line disc", lineDiscTotal)
                    PTotalChip("Trans", transportTotal)
                    if (debitAmt > 0) PTotalChip("Debit", -debitAmt, danger = true)
                }
                Text(
                    "Amount in words: ${pRupeesInWords(netTotal)}",
                    style = MaterialTheme.typography.labelSmall,
                    fontStyle = FontStyle.Italic,
                    color = PharmaMuted,
                )
            }
            // Extra space so the last fields can scroll fully above the keyboard.
            Spacer(Modifier.height(if (imeVisible) 96.dp else 16.dp))
        }
    }

    // Distributor picker
    if (supplierSheet) {
        var q by remember { mutableStateOf("") }
        var hits by remember { mutableStateOf<List<JsonObject>?>(null) }
        var searching by remember { mutableStateOf(false) }
        LaunchedEffect(q) {
            val t = q.trim()
            if (t.isEmpty()) {
                hits = null
                return@LaunchedEffect
            }
            delay(250)
            searching = true
            hits = runCatching { withContext(Dispatchers.IO) { repo.searchSuppliers(t) } }.getOrNull()
                ?: distributors.filter { it.str("name").contains(t, ignoreCase = true) }
            searching = false
        }
        val list = hits ?: distributors
        PSearchSheet(
            title = "Select distributor",
            subtitle = "${list.size} distributor(s)${if (q.isBlank() && !distributorsLoading) " · full list" else ""}",
            query = q,
            onQuery = { q = it.uppercase() },
            placeholder = "Search distributor…",
            loading = searching || distributorsLoading,
            onDismiss = { supplierSheet = false },
        ) {
            items(list) { s ->
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
                        when {
                            distributorsLoading -> "Loading distributors…"
                            q.isNotBlank() -> "No distributors match “${q.trim()}”"
                            else -> "No distributors found — add one in Distributor Master"
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
            onDismiss = { quickMaster = null },
            onCreated = {
                val createdName = nameSnapshot
                quickMaster = null
                scope.launch {
                    if (key == "distributors") {
                        val list = runCatching { withContext(Dispatchers.IO) { repo.allDistributors() } }.getOrDefault(emptyList())
                        distributors = list
                        val hit = list.firstOrNull { it.str("name").equals(createdName, ignoreCase = true) }
                            ?: list.firstOrNull { createdName.isNotBlank() && it.str("name").contains(createdName, ignoreCase = true) }
                        if (hit != null) {
                            pickSupplier(hit)
                            toast = "Distributor added and selected."
                        } else {
                            toast = "Distributor created. Select it from the list."
                            supplierSheet = true
                        }
                    } else {
                        val q = createdName.ifBlank { productQuery }.trim()
                        val hits = if (q.isBlank()) emptyList()
                        else runCatching {
                            withContext(Dispatchers.IO) { repo.searchProducts(q) }
                        }.getOrDefault(emptyList())
                        val hit = hits.firstOrNull { it.str("name").equals(createdName, ignoreCase = true) }
                            ?: hits.firstOrNull()
                        if (hit != null) {
                            selectProduct(hit)
                            toast = "Product added and selected. Continue your purchase."
                        } else {
                            productQuery = createdName
                            entry = entry.copy(productName = createdName, masterProductId = null)
                            productListOpen = true
                            toast = "Product created. Search and select it to continue."
                        }
                    }
                }
            },
        )
    }

    // Pack picker
    packSheetFor?.let { target ->
        var q by remember(target) { mutableStateOf("") }
        val key = pPackKey(q)
        val filtered = remember(q, packOptions) {
            if (q.isBlank()) packOptions.take(60)
            else packOptions.filter {
                val label = pPackLabel(it).lowercase()
                label.contains(q.lowercase()) || pPackKey(label).contains(key) || pPackKey(it.str("pack")).contains(key)
            }.take(60)
        }
        val current = if (target == -1L) entry.pack else lines.firstOrNull { it.key == target }?.pack.orEmpty()
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
                item {
                    PSheetRow(
                        title = "Use “${q.trim()}”",
                        subtitle = "Units per pack worked out from the label",
                        selected = false,
                        onClick = {
                            val typed = q.trim()
                            packSheetFor = null
                            applyPack(target, typed, null)
                            scope.launch {
                                runCatching { withContext(Dispatchers.IO) { repo.parsePack(typed) } }.onSuccess { parsed ->
                                    val pk = parsed.str("pack")
                                    if (pk.isNotBlank()) {
                                        val master = pFindPack(packOptions, pk)
                                        applyPack(
                                            target,
                                            master?.str("pack") ?: pk,
                                            master?.jInt("units") ?: parsed.jInt("units").takeIf { it > 0 },
                                        )
                                    }
                                }
                            }
                        },
                    )
                }
            }
            items(filtered) { p ->
                PSheetRow(
                    title = pPackLabel(p),
                    subtitle = listOf(
                        p.jInt("units").takeIf { it > 0 }?.let { "$it unit(s)" }.orEmpty(),
                        p.str("form"),
                    ).filter { it.isNotBlank() }.joinToString(" · "),
                    selected = pPackKey(p.str("pack")) == pPackKey(current),
                    onClick = {
                        packSheetFor = null
                        applyPack(target, p.str("pack").trim(), p.jInt("units"))
                    },
                )
            }
            if (filtered.isEmpty() && q.isBlank()) {
                item { Text("No pack sizes loaded.", Modifier.padding(12.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted) }
            }
        }
    }

    // Sold-stock confirmation
    soldLines?.let { sl ->
        AlertDialog(
            onDismissRequest = { soldLines = null },
            icon = { Icon(Icons.Default.Warning, null, tint = Color(0xFFD97706)) },
            title = { Text("Stock already sold", style = MaterialTheme.typography.titleSmall) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(
                        if (soldBlocked) {
                            "Some products from this bill have already been sold or adjusted, and you don't have permission to edit a purchase with sold stock. Ask your administrator if this bill needs correction."
                        } else {
                            "Some products from this bill have already been sold or adjusted. Updating will recalculate stock from your edited lines and keep the sold quantity deducted."
                        },
                        style = MaterialTheme.typography.bodySmall,
                    )
                    val hs = rememberScrollState()
                    BillLinesFrame {
                        Row(Modifier.horizontalScroll(hs)) {
                            Column {
                                Row(Modifier.background(BillGridHeaderBg), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                    BillGridTh("PRODUCT", 120.dp)
                                    BillGridTh("BATCH", 64.dp)
                                    BillGridTh("PURCH.", 52.dp, alignEnd = true)
                                    BillGridTh("SOLD", 48.dp, alignEnd = true)
                                    BillGridTh("STOCK", 48.dp, alignEnd = true)
                                }
                                sl.forEach { s ->
                                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                        BillGridCellText(s.str("product_name"), 120.dp, fontWeight = FontWeight.SemiBold)
                                        BillGridCellText(s.str("batch_no"), 64.dp)
                                        BillGridCellText(pNumText(s.amount("purchased"), false).ifBlank { "0" }, 52.dp, alignEnd = true)
                                        BillGridCellText(
                                            pNumText(s.amount("sold"), false).ifBlank { "0" },
                                            48.dp,
                                            alignEnd = true,
                                            color = MaterialTheme.colorScheme.error,
                                            fontWeight = FontWeight.SemiBold,
                                        )
                                        BillGridCellText(pNumText(s.amount("in_stock"), false).ifBlank { "0" }, 48.dp, alignEnd = true)
                                    }
                                }
                            }
                        }
                    }
                    Text(
                        if (soldBlocked) "Quantities are in units (tablets)."
                        else "Changing the batch number or removing a sold line can leave the old batch with negative stock. Quantities are in units (tablets).",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                    )
                }
            },
            confirmButton = {
                if (soldBlocked) {
                    TextButton(onClick = { soldLines = null }) { Text("Close") }
                } else {
                    Button(
                        onClick = {
                            soldLines = null
                            save(confirmSold = true)
                        },
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFFD97706)),
                    ) { Text("Edit anyway") }
                }
            },
            dismissButton = {
                if (!soldBlocked) TextButton(onClick = { soldLines = null }) { Text("Cancel") }
            },
        )
    }
}

@Composable
private fun PurchaseTotalsBar(
    itemCount: Int,
    taxable: Double,
    gst: Double,
    net: Double,
    saving: Boolean,
    isEdit: Boolean,
    onSave: () -> Unit,
) {
    Surface(shadowElevation = 8.dp, color = Color.White) {
        Row(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .padding(horizontal = 10.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    "$itemCount item(s) · Taxable ${formatAmount2(taxable)} · GST ${formatAmount2(gst)}",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Row(verticalAlignment = Alignment.Bottom) {
                    Text("NET ", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    Text(
                        formatAmount2(net),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = PharmaTeal,
                        maxLines = 1,
                    )
                }
            }
            Button(
                onClick = onSave,
                enabled = !saving,
                shape = RoundedCornerShape(8.dp),
                colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal),
                contentPadding = PaddingValues(horizontal = 14.dp),
                modifier = Modifier.height(40.dp),
            ) {
                if (saving) {
                    CircularProgressIndicator(Modifier.size(16.dp), color = Color.White, strokeWidth = 2.dp)
                } else {
                    Icon(Icons.Default.Save, null, Modifier.size(16.dp))
                }
                Spacer(Modifier.width(6.dp))
                Text(if (isEdit) "Update" else "Save purchase", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

// ───────────────────────── Lines table ─────────────────────────

private object PLineCols {
    val no = 22.dp
    val product = 140.dp
    val pack = 68.dp
    val hsn = 64.dp
    val batch = 78.dp
    val exp = 78.dp
    val qty = 44.dp
    val rate = 60.dp
    val gst = 50.dp
    val disc = 52.dp
    val net = 74.dp
    val action = 30.dp
    val gap = 4.dp
}

@Composable
private fun PurchaseLinesTable(
    lines: List<PurchaseLineDraft>,
    maxBodyHeight: Dp,
    onUpdate: (Long, PField, (PurchaseLineDraft) -> PurchaseLineDraft) -> Unit,
    onPack: (Long) -> Unit,
    onRemove: (Long) -> Unit,
) {
    val scroll = rememberScrollState()
    val bodyScroll = rememberScrollState()
    LaunchedEffect(lines.size) { bodyScroll.animateScrollTo(bodyScroll.maxValue) }
    BillLinesFrame {
        Row(
            Modifier.fillMaxWidth().horizontalScroll(scroll).background(BillGridHeaderBg),
            horizontalArrangement = Arrangement.spacedBy(PLineCols.gap),
        ) {
            BillGridTh("#", PLineCols.no)
            BillGridTh("PRODUCT", PLineCols.product)
            BillGridTh("PACK", PLineCols.pack)
            BillGridTh("HSN", PLineCols.hsn)
            BillGridTh("BATCH", PLineCols.batch)
            BillGridTh("EXP", PLineCols.exp)
            BillGridTh("QTY", PLineCols.qty, alignEnd = true)
            BillGridTh("U/PK", PLineCols.qty, alignEnd = true)
            BillGridTh("FREE", PLineCols.qty, alignEnd = true)
            BillGridTh("RATE", PLineCols.rate, alignEnd = true)
            BillGridTh("MRP", PLineCols.rate, alignEnd = true)
            BillGridTh("PTR", PLineCols.rate, alignEnd = true)
            BillGridTh("PTS", PLineCols.rate, alignEnd = true)
            BillGridTh("GST", PLineCols.gst)
            BillGridTh("DISC%", PLineCols.disc, alignEnd = true)
            BillGridTh("DISC₹", PLineCols.rate, alignEnd = true)
            BillGridTh("NET", PLineCols.net, alignEnd = true)
            BillGridTh("", PLineCols.action)
        }
        HorizontalDivider(color = PharmaBorder)
        Column(Modifier.heightIn(max = maxBodyHeight).verticalScroll(bodyScroll)) {
            lines.forEachIndexed { index, l ->
                key(l.key) {
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .horizontalScroll(scroll)
                            .padding(vertical = 3.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(PLineCols.gap),
                    ) {
                        BillGridCellText("${index + 1}", PLineCols.no, color = PharmaMuted)
                        BillGridCellText(l.productName, PLineCols.product, fontWeight = FontWeight.SemiBold)
                        Box(
                            Modifier
                                .width(PLineCols.pack)
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
                        PCellInput(l.hsn, { v -> onUpdate(l.key, PField.Other) { it.copy(hsn = v.filter(Char::isDigit).take(8)) } }, PLineCols.hsn, KeyboardType.Number)
                        PCellInput(l.batch, { v -> onUpdate(l.key, PField.Other) { it.copy(batch = v.uppercase()) } }, PLineCols.batch)
                        PCellExpiry(l.expiry, { ym -> onUpdate(l.key, PField.Other) { it.copy(expiry = ym) } }, PLineCols.exp)
                        PCellNum(l.qty.toDouble(), { v -> onUpdate(l.key, PField.Qty) { it.copy(qty = v.toInt()) } }, PLineCols.qty, isInt = true)
                        PCellNum(l.upp.toDouble(), { v -> onUpdate(l.key, PField.Other) { it.copy(upp = v.toInt()) } }, PLineCols.qty, isInt = true)
                        PCellNum(l.free.toDouble(), { v -> onUpdate(l.key, PField.Other) { it.copy(free = v.toInt()) } }, PLineCols.qty, isInt = true)
                        PCellNum(l.rate, { v -> onUpdate(l.key, PField.Rate) { it.copy(rate = v) } }, PLineCols.rate)
                        PCellNum(l.mrp, { v -> onUpdate(l.key, PField.Other) { it.copy(mrp = v) } }, PLineCols.rate)
                        PCellNum(l.ptr, { v -> onUpdate(l.key, PField.Other) { it.copy(ptr = v) } }, PLineCols.rate)
                        PCellNum(l.pts, { v -> onUpdate(l.key, PField.Other) { it.copy(pts = v) } }, PLineCols.rate)
                        PCellGst(l.gst, { g -> onUpdate(l.key, PField.Other) { it.copy(gst = g) } }, PLineCols.gst)
                        PCellNum(l.discPct, { v -> onUpdate(l.key, PField.DiscPct) { it.copy(discPct = v) } }, PLineCols.disc)
                        PCellNum(l.discAmt, { v -> onUpdate(l.key, PField.DiscAmt) { it.copy(discAmt = v) } }, PLineCols.rate)
                        BillGridCellText(
                            formatAmount2(pTotal(l)),
                            PLineCols.net,
                            alignEnd = true,
                            color = Color(0xFF0284C7),
                            fontWeight = FontWeight.SemiBold,
                        )
                        IconButton(onClick = { onRemove(l.key) }, modifier = Modifier.size(PLineCols.action)) {
                            Icon(Icons.Default.Close, "Remove", Modifier.size(16.dp), tint = MaterialTheme.colorScheme.error)
                        }
                    }
                }
                if (index < lines.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.45f))
            }
        }
    }
}

@OptIn(androidx.compose.foundation.ExperimentalFoundationApi::class)
@Composable
internal fun PCellInput(
    text: String,
    onText: (String) -> Unit,
    width: Dp,
    keyboardType: KeyboardType = KeyboardType.Text,
    alignEnd: Boolean = false,
    placeholder: String = "",
) {
    val bringIntoViewRequester = remember { BringIntoViewRequester() }
    val scope = rememberCoroutineScope()
    BasicTextField(
        value = text,
        onValueChange = onText,
        singleLine = true,
        textStyle = MaterialTheme.typography.labelSmall.copy(
            color = PharmaSlate,
            textAlign = if (alignEnd) TextAlign.End else TextAlign.Start,
        ),
        cursorBrush = SolidColor(PharmaTeal),
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType, imeAction = ImeAction.Next),
        modifier = Modifier
            .bringIntoViewRequester(bringIntoViewRequester)
            .width(width)
            .height(30.dp)
            .clip(RoundedCornerShape(5.dp))
            .background(Color.White)
            .border(1.dp, Color(0xFFE2E8F0), RoundedCornerShape(5.dp))
            .padding(horizontal = 4.dp)
            .onFocusChanged { st ->
                if (st.isFocused) {
                    scope.launch {
                        delay(320)
                        bringIntoViewRequester.bringIntoView()
                    }
                }
            },
        decorationBox = { inner ->
            Box(
                Modifier.fillMaxSize(),
                contentAlignment = if (alignEnd) Alignment.CenterEnd else Alignment.CenterStart,
            ) {
                if (text.isEmpty() && placeholder.isNotEmpty()) {
                    Text(placeholder, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
                inner()
            }
        },
    )
}

@Composable
internal fun PCellNum(value: Double, onValue: (Double) -> Unit, width: Dp, isInt: Boolean = false) {
    var text by remember { mutableStateOf(pNumText(value, isInt)) }
    LaunchedEffect(value) {
        if ((text.toDoubleOrNull() ?: 0.0) != value) text = pNumText(value, isInt)
    }
    PCellInput(
        text = text,
        onText = { raw ->
            val clean = pCleanNum(raw, isInt)
            text = clean
            onValue(clean.toDoubleOrNull() ?: 0.0)
        },
        width = width,
        keyboardType = if (isInt) KeyboardType.Number else KeyboardType.Decimal,
        alignEnd = true,
        placeholder = "0",
    )
}

@Composable
internal fun PCellExpiry(ym: String, onYm: (String) -> Unit, width: Dp) {
    ExpiryMmYyCell(ym = ym, onYm = onYm, width = width)
}

@Composable
internal fun PCellGst(gst: Double, onGst: (Double) -> Unit, width: Dp) {
    var open by remember { mutableStateOf(false) }
    Box(Modifier.width(width)) {
        Row(
            Modifier
                .fillMaxWidth()
                .height(30.dp)
                .clip(RoundedCornerShape(5.dp))
                .background(Color.White)
                .border(1.dp, Color(0xFFE2E8F0), RoundedCornerShape(5.dp))
                .clickable { open = true }
                .padding(start = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                "${pNumText(gst, false).ifBlank { "0" }}%",
                Modifier.weight(1f),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaSlate,
            )
            Icon(Icons.Default.ArrowDropDown, null, Modifier.size(16.dp), tint = PharmaMuted)
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            PGstOptions.forEach { g ->
                DropdownMenuItem(
                    text = { Text("${pNumText(g, false).ifBlank { "0" }}%", style = MaterialTheme.typography.bodySmall) },
                    onClick = {
                        onGst(g)
                        open = false
                    },
                )
            }
        }
    }
}

// ───────────────────────── Form building blocks ─────────────────────────

@Composable
internal fun PSection(
    title: String,
    expanded: Boolean,
    onToggle: (() -> Unit)?,
    summary: String,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(Color.White)
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .padding(8.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Row(
            Modifier
                .fillMaxWidth()
                .then(if (onToggle != null) Modifier.clickable { onToggle() } else Modifier),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier
                    .size(width = 3.dp, height = 14.dp)
                    .clip(RoundedCornerShape(2.dp))
                    .background(PharmaTeal),
            )
            Spacer(Modifier.width(6.dp))
            Text(
                title.uppercase(),
                style = MaterialTheme.typography.labelSmall,
                fontWeight = FontWeight.Bold,
                color = PharmaSlate,
            )
            Spacer(Modifier.width(8.dp))
            Text(
                if (!expanded) summary else "",
                Modifier.weight(1f),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaTeal,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            if (onToggle != null) {
                Icon(
                    if (expanded) Icons.Default.ExpandLess else Icons.Default.ExpandMore,
                    contentDescription = if (expanded) "Collapse" else "Expand",
                    modifier = Modifier.size(20.dp),
                    tint = PharmaSlate,
                )
            }
        }
        if (expanded) content()
    }
}

@Composable
internal fun PLabel(label: String, isError: Boolean = false) {
    Text(
        label,
        style = MaterialTheme.typography.labelSmall,
        color = if (isError) MaterialTheme.colorScheme.error else PharmaMuted,
        maxLines = 1,
        overflow = TextOverflow.Ellipsis,
    )
}

@Composable
internal fun PNumField(
    label: String,
    value: Double,
    onValue: (Double) -> Unit,
    modifier: Modifier = Modifier,
    isInt: Boolean = false,
) {
    var text by remember { mutableStateOf(pNumText(value, isInt)) }
    LaunchedEffect(value) {
        if ((text.toDoubleOrNull() ?: 0.0) != value) text = pNumText(value, isInt)
    }
    Column(modifier) {
        PLabel(label)
        Spacer(Modifier.height(1.dp))
        SalesDenseSearchField(
            value = text,
            onValueChange = { raw ->
                val clean = pCleanNum(raw, isInt)
                text = clean
                onValue(clean.toDoubleOrNull() ?: 0.0)
            },
            placeholder = "0",
            modifier = Modifier.fillMaxWidth(),
            keyboardOptions = KeyboardOptions(
                keyboardType = if (isInt) KeyboardType.Number else KeyboardType.Decimal,
                imeAction = ImeAction.Next,
            ),
        )
    }
}

@Composable
internal fun PReadField(label: String, value: String, modifier: Modifier = Modifier, highlight: Boolean = false) {
    Column(modifier) {
        PLabel(label)
        Spacer(Modifier.height(1.dp))
        Box(
            Modifier
                .fillMaxWidth()
                .height(SalesDenseFieldHeight)
                .clip(RoundedCornerShape(6.dp))
                .background(PReadBg)
                .border(1.dp, Color(0xFFE2E8F0), RoundedCornerShape(6.dp))
                .padding(horizontal = 8.dp),
            contentAlignment = Alignment.CenterStart,
        ) {
            Text(
                value,
                style = MaterialTheme.typography.bodySmall,
                color = if (highlight) PharmaTeal else PharmaSlate,
                fontWeight = if (highlight) FontWeight.SemiBold else FontWeight.Normal,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

@Composable
internal fun PPickField(
    label: String,
    value: String,
    placeholder: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    icon: ImageVector = Icons.Default.ArrowDropDown,
) {
    Column(modifier) {
        PLabel(label)
        Spacer(Modifier.height(1.dp))
        Row(
            Modifier
                .fillMaxWidth()
                .height(SalesDenseFieldHeight)
                .clip(RoundedCornerShape(6.dp))
                .background(Color.White)
                .border(1.dp, PFieldBorder, RoundedCornerShape(6.dp))
                .clickable(onClick = onClick)
                .padding(start = 8.dp, end = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                value.ifBlank { placeholder },
                Modifier.weight(1f),
                style = if (value.isBlank()) MaterialTheme.typography.labelSmall else MaterialTheme.typography.bodySmall,
                color = if (value.isBlank()) PharmaMuted else PharmaSlate,
                fontWeight = if (value.isBlank()) FontWeight.Normal else FontWeight.SemiBold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Icon(icon, null, Modifier.size(18.dp), tint = PharmaMuted)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun POptionDropdown(
    label: String,
    value: String,
    options: List<String>,
    onSelect: (String) -> Unit,
    modifier: Modifier = Modifier,
    display: (String) -> String = { it.ifBlank { "— Select —" } },
) {
    var expanded by remember { mutableStateOf(false) }
    CompactSelectDropdown(
        label = label,
        value = display(value),
        expanded = expanded,
        onExpandedChange = { expanded = it },
        modifier = modifier,
        dense = true,
    ) {
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            options.forEach { o ->
                DropdownMenuItem(
                    text = {
                        Text(
                            display(o),
                            style = MaterialTheme.typography.bodySmall,
                            fontWeight = if (o == value) FontWeight.SemiBold else FontWeight.Normal,
                            color = if (o == value) PharmaTeal else PharmaSlate,
                        )
                    },
                    onClick = {
                        onSelect(o)
                        expanded = false
                    },
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PDateField(
    label: String,
    iso: String,
    onChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    readOnly: Boolean = false,
) {
    var open by remember { mutableStateOf(false) }
    val shown = if (iso.isBlank()) "" else formatSaleDate(iso)
    if (readOnly) {
        PReadField(label, shown.ifBlank { "—" }, modifier)
    } else {
        PPickField(label, shown, "dd-MM-yyyy", { open = true }, modifier, icon = Icons.Default.CalendarMonth)
    }
    if (open) {
        val initial = runCatching {
            LocalDate.parse(iso.take(10)).atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli()
        }.getOrNull()
        val state = rememberDatePickerState(initialSelectedDateMillis = initial)
        DatePickerDialog(
            onDismissRequest = { open = false },
            confirmButton = {
                TextButton(onClick = {
                    state.selectedDateMillis?.let {
                        onChange(Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate().toString())
                    }
                    open = false
                }) { Text("OK") }
            },
            dismissButton = { TextButton(onClick = { open = false }) { Text("Cancel") } },
        ) {
            DatePicker(state = state)
        }
    }
}

@Composable
internal fun PTotalChip(label: String, value: Double, danger: Boolean = false) {
    Row(
        Modifier
            .clip(RoundedCornerShape(6.dp))
            .background(if (danger) Color(0xFFFEE2E2) else PReadBg)
            .padding(horizontal = 8.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text("$label ", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        Text(
            formatAmount2(value),
            style = MaterialTheme.typography.labelSmall,
            fontWeight = FontWeight.SemiBold,
            color = if (danger) MaterialTheme.colorScheme.error else PharmaSlate,
        )
    }
}

@Composable
private fun PProductHits(
    hits: List<JsonObject>,
    searching: Boolean,
    query: String,
    maxHeight: Dp,
    onPick: (JsonObject) -> Unit,
    onClose: () -> Unit,
    canAddNew: Boolean = false,
    onAddNew: () -> Unit = {},
) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .border(1.dp, PFieldBorder, RoundedCornerShape(6.dp)),
    ) {
        Row(
            Modifier.fillMaxWidth().background(BillGridHeaderBg).padding(start = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                if (searching) "Searching…" else "${hits.size} product(s)",
                Modifier.weight(1f),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
            IconButton(onClick = onClose, modifier = Modifier.size(28.dp)) {
                Icon(Icons.Default.ExpandLess, "Hide list", Modifier.size(18.dp), tint = PharmaSlate)
            }
        }
        Column(Modifier.heightIn(min = 40.dp, max = maxHeight).verticalScroll(rememberScrollState())) {
            if (hits.isEmpty() && !searching) {
                Text(
                    if (query.isNotBlank()) "No products match"
                    else "Type to search…",
                    Modifier.padding(10.dp),
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
            }
            hits.take(50).forEachIndexed { i, p ->
                val sub = listOf(
                    p.str("manufacturer").let { m ->
                        val short = p.str("manufacturer_short")
                        if (short.isNotBlank() && m.isNotBlank()) "$m ($short)" else m
                    },
                    p.str("schedule_category"),
                    p.str("pack_size"),
                    p.str("generic_name"),
                    p.str("dosage_form"),
                    p.str("strength"),
                ).filter { it.isNotBlank() }.joinToString(" · ")
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clickable { onPick(p) }
                        .padding(horizontal = 8.dp, vertical = 5.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            p.str("name"),
                            style = MaterialTheme.typography.bodySmall,
                            fontWeight = FontWeight.SemiBold,
                            color = PharmaSlate,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                        if (sub.isNotBlank()) {
                            Text(sub, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                    }
                    if (p.amount("mrp") > 0) {
                        Text(formatAmount2(p.amount("mrp")), style = MaterialTheme.typography.labelSmall, color = PharmaTeal)
                    }
                }
                if (i < hits.size - 1) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.4f))
            }
            if (canAddNew) {
                HorizontalDivider(color = PharmaBorder.copy(alpha = 0.4f))
                MasterAddNewRow(
                    label = masterAddNewLabel("products", query),
                    onClick = onAddNew,
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PSearchSheet(
    title: String,
    subtitle: String?,
    query: String,
    onQuery: (String) -> Unit,
    placeholder: String,
    loading: Boolean,
    onDismiss: () -> Unit,
    content: LazyListScope.() -> Unit,
) {
    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = 12.dp)
                .imePadding(),
        ) {
            Text(title, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            subtitle?.takeIf { it.isNotBlank() }?.let {
                Text(it, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
            Spacer(Modifier.height(6.dp))
            SalesDenseSearchField(
                value = query,
                onValueChange = onQuery,
                placeholder = placeholder,
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                trailingIcon = {
                    when {
                        loading -> CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                        query.isNotEmpty() -> IconButton(onClick = { onQuery("") }, modifier = Modifier.size(22.dp)) {
                            Icon(Icons.Default.Close, "Clear", Modifier.size(14.dp), tint = PharmaMuted)
                        }
                    }
                },
            )
            Spacer(Modifier.height(4.dp))
            LazyColumn(Modifier.fillMaxWidth().heightIn(max = 440.dp)) { content() }
            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
internal fun PSheetRow(title: String, subtitle: String, selected: Boolean, onClick: () -> Unit) {
    Column {
        Row(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(6.dp))
                .background(if (selected) Color(0xFFF0FDFA) else Color.Transparent)
                .clickable(onClick = onClick)
                .padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    title,
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.SemiBold,
                    color = if (selected) PharmaTeal else PharmaSlate,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                if (subtitle.isNotBlank()) {
                    Text(subtitle, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
        HorizontalDivider(color = PharmaBorder.copy(alpha = 0.4f))
    }
}
