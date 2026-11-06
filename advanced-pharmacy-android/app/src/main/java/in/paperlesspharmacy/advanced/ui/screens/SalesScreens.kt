package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.isImeVisible
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.ui.focus.FocusState
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.BorderStroke
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountBalanceWallet
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.LocalShipping
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Print
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.ui.res.painterResource
import `in`.paperlesspharmacy.advanced.R
import androidx.compose.material3.*
import androidx.compose.runtime.*
import android.content.Intent
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.core.content.FileProvider
import java.io.File
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import kotlin.math.round
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaBorder
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaSlate
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaMuted
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTeal
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.CompactFieldHeight
import `in`.paperlesspharmacy.advanced.ui.theme.CompactFormHeaderGap
import `in`.paperlesspharmacy.advanced.ui.theme.CompactScreenPadding
import `in`.paperlesspharmacy.advanced.ui.util.formatAmount2
import `in`.paperlesspharmacy.advanced.ui.theme.SalesDenseButtonHeight
import `in`.paperlesspharmacy.advanced.ui.theme.SalesDenseFieldHeight
import `in`.paperlesspharmacy.advanced.util.PdfWifiPrint
import `in`.paperlesspharmacy.advanced.ui.util.formatInr
import `in`.paperlesspharmacy.advanced.ui.util.formatMrp
import `in`.paperlesspharmacy.advanced.ui.util.formatSaleDate
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate
data class SaleLineDraft(
    val lineKey: Long = System.nanoTime(),
    val bindingId: Int,
    val productName: String,
    val schedule: String,
    val batchId: Int,
    val batchNo: String,
    val expiry: String,
    val rate: Double,
    val gstRate: Double,
    val hsn: String,
    val unitsPerPack: Int,
    val qty: Int,
    val discountPct: Double = 0.0,
    val discAmt: Double = 0.0,
    /** `unit` = loose tabs (retail); `pack` = strips (wholesale — web wsales). */
    val unitType: String = "unit",
    val mfg: String = "",
    val shelf: String = "",
    val pack: String = "",
    val purchaseRate: Double = 0.0,
    /** Strip / pack MRP (wholesale); for retail display may be pack MRP. */
    val packMrp: Double = 0.0,
    val unitMrp: Double = 0.0,
    val unitNetCost: Double = 0.0,
    val packPurchaseRate: Double = 0.0,
    val ptr: Double = 0.0,
    val pts: Double = 0.0,
    val othRate: Double = 0.0,
    val freeQty: Int = 0,
    val category: String = "Allopathy",
    val schemePct: Double = 0.0,
    val batches: List<JsonObject> = emptyList(),
)

private fun round2(v: Double): Double = round(v * 100.0) / 100.0

/** Bill net → nearest rupee, halves up (web Math.round / PHP round). 71.76 → 72, 71.50 → 72. */
private fun roundRupee(v: Double): Double = kotlin.math.floor(v + 0.5 + 1e-9)

private val SaleCounters = listOf("COUNTER 1", "COUNTER 2", "COUNTER 3", "CASH COUNTER", "IPD COUNTER")
private const val CASH_COUNTER = "CASH COUNTER"
private const val IPD_COUNTER = "IPD COUNTER"
/** Walk-in cash counter: Schedule H / X stay hidden. H1 can be added, then sent to Counter 1–3 from the product row. */
private val CashCounterHiddenSchedules = setOf("H", "X")
private val CashCounterMoveSchedules = setOf("H1")

private fun isH1Schedule(schedule: String): Boolean =
    schedule.trim().uppercase() in CashCounterMoveSchedules

private fun isNormalSaleCounter(name: String): Boolean {
    val u = name.trim().uppercase()
    return u != CASH_COUNTER && u != IPD_COUNTER
}

/** One counter's open bill — patient + lines stay separate per counter (web `bills[counter]`). */
private class CounterBill(cashCounter: Boolean) {
    val billMode = mutableStateOf("retail")
    val saleType = mutableStateOf(if (cashCounter) "cash" else "regular")
    /** Wholesale rate column: ptr | pts (web `rateType`). */
    val rateType = mutableStateOf("ptr")
    /** Cash discount % applied after line discount (web `cdDiscPct`). */
    val cdDiscPct = mutableDoubleStateOf(0.0)
    val billDate = mutableStateOf(LocalDate.now().toString())
    val customerName = mutableStateOf("")
    val customerId = mutableIntStateOf(0)
    val phone = mutableStateOf("")
    val email = mutableStateOf("")
    val address = mutableStateOf("")
    val doctor = mutableStateOf("")
    val narration = mutableStateOf("")
    val billDiscAmt = mutableStateOf("0")
    val paymentMode = mutableStateOf("Cash")
    val accountId = mutableIntStateOf(0)
    val cashAmount = mutableStateOf("")
    val lines = mutableStateOf<List<SaleLineDraft>>(emptyList())
    val customerExpanded = mutableStateOf(true)
    val phoneMatches = mutableStateOf<List<JsonObject>>(emptyList())
    val lookupNote = mutableStateOf<String?>(null)
    /** Linked AuraGharTak order — sent as `agt_order_id`; server marks the order Ready on save. */
    val agtOrderId = mutableIntStateOf(0)
    val agtOrderNo = mutableStateOf("")
    val agtRxUrl = mutableStateOf("")
    val agtPending = mutableStateOf<List<JsonObject>>(emptyList())

    val inUse: Boolean get() = lines.value.isNotEmpty() || customerName.value.isNotBlank()
    val label: String? get() = customerName.value.trim().takeIf { it.isNotEmpty() && !it.equals("walk-in", true) }

    fun copyFrom(other: CounterBill) {
        billMode.value = other.billMode.value
        saleType.value = if (other.saleType.value.equals("cash", true)) "regular" else other.saleType.value
        rateType.value = other.rateType.value
        cdDiscPct.doubleValue = other.cdDiscPct.doubleValue
        billDate.value = other.billDate.value
        customerName.value = other.customerName.value
        customerId.intValue = other.customerId.intValue
        phone.value = other.phone.value
        email.value = other.email.value
        address.value = other.address.value
        doctor.value = other.doctor.value
        narration.value = other.narration.value
        billDiscAmt.value = other.billDiscAmt.value
        paymentMode.value = other.paymentMode.value
        accountId.intValue = other.accountId.intValue
        cashAmount.value = other.cashAmount.value
        lines.value = other.lines.value.toList()
        customerExpanded.value = other.customerExpanded.value
        phoneMatches.value = other.phoneMatches.value
        lookupNote.value = other.lookupNote.value
        agtOrderId.intValue = other.agtOrderId.intValue
        agtOrderNo.value = other.agtOrderNo.value
        agtRxUrl.value = other.agtRxUrl.value
        agtPending.value = other.agtPending.value
    }

    fun resetCashWalkIn() {
        billMode.value = "retail"
        saleType.value = "cash"
        rateType.value = "ptr"
        cdDiscPct.doubleValue = 0.0
        billDate.value = LocalDate.now().toString()
        customerName.value = ""
        customerId.intValue = 0
        phone.value = ""
        email.value = ""
        address.value = ""
        doctor.value = ""
        narration.value = ""
        billDiscAmt.value = "0"
        paymentMode.value = "Cash"
        accountId.intValue = 0
        cashAmount.value = ""
        lines.value = emptyList()
        customerExpanded.value = true
        phoneMatches.value = emptyList()
        lookupNote.value = null
        agtOrderId.intValue = 0
        agtOrderNo.value = ""
        agtRxUrl.value = ""
        agtPending.value = emptyList()
    }
}

private fun lineGross(line: SaleLineDraft): Double = round2(line.rate * line.qty)

private fun lineDiscAmt(line: SaleLineDraft): Double {
    if (line.discAmt > 0) return round2(line.discAmt)
    return round2(lineGross(line) * line.discountPct / 100.0)
}

/** Retail GST-inclusive (matches web billing). */
private fun lineTaxableRetail(line: SaleLineDraft): Double {
    val base = line.rate * line.qty * (1 - line.discountPct / 100.0)
    return if (line.gstRate > 0) round2(base / (1 + line.gstRate / 100.0)) else round2(base)
}

private fun lineGstRetail(line: SaleLineDraft): Double {
    val base = line.rate * line.qty * (1 - line.discountPct / 100.0)
    return round2(base - lineTaxableRetail(line))
}

private fun lineNetRetail(line: SaleLineDraft): Double =
    round2(line.rate * line.qty * (1 - line.discountPct / 100.0))

private fun lineProfitRetail(line: SaleLineDraft): Double {
    val gross = line.rate * line.qty
    val disc = lineDiscAmt(line)
    val unitCost = line.unitNetCost.takeIf { it > 0 } ?: line.purchaseRate
    return round2(gross - disc - unitCost * line.qty)
}

private fun saleLineAmount(line: SaleLineDraft): Double = lineNetRetail(line)

private fun syncDiscFromPct(line: SaleLineDraft, pct: Double): SaleLineDraft {
    val disc = pct.coerceAtLeast(0.0)
    val gross = round2(line.rate * line.qty)
    return line.copy(
        discountPct = disc,
        discAmt = if (gross > 0) round2(gross * disc / 100.0) else 0.0,
    )
}

private fun syncDiscFromAmt(line: SaleLineDraft, amt: Double): SaleLineDraft {
    val discAmt = amt.coerceAtLeast(0.0)
    val gross = round2(line.rate * line.qty)
    val pct = if (gross > 0) round2(discAmt / gross * 100.0) else 0.0
    return line.copy(discAmt = discAmt, discountPct = pct)
}

private fun saleBillNet(lines: List<SaleLineDraft>, billDiscount: Double): Double {
    val sub = lines.sumOf { saleLineAmount(it) }
    return (sub - billDiscount).coerceAtLeast(0.0)
}

// ── Wholesale strip / pack parity (web billing.ts wsales) ─────────────────────

/** Strip MRP from batch: purchase pack MRP, or unit × units_per_pack. */
private fun wholesaleStripMrpFromBatch(batch: JsonObject, fallbackUpp: Int): Double {
    val upp = batch.jInt("units_per_pack").takeIf { it > 0 } ?: fallbackUpp.coerceAtLeast(1)
    var pack = batch.amount("pack_mrp")
    val unit = batch.amount("unit_mrp").takeIf { it > 0 } ?: batch.amount("mrp")
    if (upp > 1 && pack > 0 && unit > 0 && kotlin.math.abs(pack - unit) < 0.02) {
        pack = unit * upp
    } else if (pack <= 0 && unit > 0) {
        pack = unit * upp
    }
    if (pack > 0) return round2(pack)
    if (upp > 1 && unit > 0 && unit <= 15.0) return round2(unit * upp)
    return round2(unit)
}

private fun marginForCategory(margins: List<JsonObject>, category: String): JsonObject? {
    val cat = category.trim().ifBlank { "Allopathy" }
    return margins.firstOrNull { it.str("category").equals(cat, true) }
        ?: margins.firstOrNull { it.str("category").equals("Allopathy", true) }
        ?: margins.firstOrNull()
}

/** PTR/PTS from strip MRP + rate-margin % (web resolveWholesalePackRates). */
private fun repriceWholesaleLine(
    line: SaleLineDraft,
    rateType: String,
    margins: List<JsonObject>,
): SaleLineDraft {
    val upp = line.unitsPerPack.coerceAtLeast(1)
    var strip = line.packMrp
    val unit = line.unitMrp
    if (upp > 1 && strip > 0 && unit > 0 && kotlin.math.abs(strip - unit) < 0.02) {
        strip = round2(unit * upp)
    } else if (strip <= 0 && unit > 0) {
        strip = if (upp > 1 && unit <= 15.0) round2(unit * upp) else round2(unit)
    }
    val margin = marginForCategory(margins, line.category)
    val ptrM = margin?.amount("ptr_margin")?.takeIf { it > 0 } ?: 20.0
    val ptsM = margin?.amount("pts_margin")?.takeIf { it > 0 } ?: 30.0
    var ptr = if (strip > 0) round2(strip * (1 - ptrM / 100.0)) else line.ptr
    var pts = if (strip > 0) round2(strip * (1 - ptsM / 100.0)) else line.pts
    if (ptr <= 0) ptr = line.ptr
    if (pts <= 0) pts = line.pts
    val packRate = if (rateType == "pts") {
        if (pts > 0) pts else ptr
    } else {
        if (ptr > 0) ptr else pts
    }
    val rate = if (packRate > 0) packRate else line.rate
    val oth = if (rateType == "pts") ptr else pts
    var packPurchase = line.packPurchaseRate
    if (packPurchase <= 0 && line.purchaseRate > 0) packPurchase = round2(line.purchaseRate * upp)
    if (packPurchase > 0 && line.schemePct > 0) {
        packPurchase = round2(packPurchase * 100.0 / (100.0 + line.schemePct))
    }
    return line.copy(
        packMrp = strip,
        unitMrp = if (strip > 0) round2(strip / upp) else unit,
        unitType = "pack",
        ptr = ptr,
        pts = pts,
        rate = rate,
        othRate = oth,
        packPurchaseRate = packPurchase,
        freeQty = line.freeQty.coerceAtLeast(0),
    )
}

private fun lineCdWholesale(line: SaleLineDraft, cdPct: Double): Double {
    val afterDisc = lineGross(line) - lineDiscAmt(line)
    return round2(afterDisc * cdPct.coerceAtLeast(0.0) / 100.0)
}

private fun lineTaxableWholesale(line: SaleLineDraft, cdPct: Double): Double =
    round2(lineGross(line) - lineDiscAmt(line) - lineCdWholesale(line, cdPct))

private fun lineGstWholesale(line: SaleLineDraft, cdPct: Double): Double =
    round2(lineTaxableWholesale(line, cdPct) * line.gstRate / 100.0)

/** Wholesale GST-exclusive net (taxable + GST). */
private fun lineNetWholesale(line: SaleLineDraft, cdPct: Double): Double =
    round2(lineTaxableWholesale(line, cdPct) + lineGstWholesale(line, cdPct))

private fun lineProfitWholesale(line: SaleLineDraft): Double {
    val packCost = line.packPurchaseRate.takeIf { it > 0 }
        ?: round2(line.purchaseRate * line.unitsPerPack.coerceAtLeast(1))
    return round2(lineGross(line) - lineDiscAmt(line) - packCost * line.qty)
}

private fun wholesaleBillNet(lines: List<SaleLineDraft>, cdPct: Double): Double =
    lines.sumOf { lineNetWholesale(it, cdPct) }.coerceAtLeast(0.0)

private fun batchStockQty(batch: JsonObject): Int {
    val q = batch.amount("quantity").takeIf { it > 0 }
        ?: batch.amount("available_qty")
    return q.toInt().coerceAtLeast(0)
}

/** Sellable strips for wholesale (= floor(base qty / upp)). */
private fun batchStripQty(batch: JsonObject, fallbackUpp: Int = 1): Int {
    val upp = batch.jInt("units_per_pack").takeIf { it > 0 } ?: fallbackUpp.coerceAtLeast(1)
    return batchStockQty(batch) / upp
}

/** OutlinedTextField enforces ~56dp min height, so dense fields are drawn on BasicTextField. */
@OptIn(androidx.compose.foundation.ExperimentalFoundationApi::class)
@Composable
internal fun SalesDenseSearchField(
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    modifier: Modifier = Modifier,
    leadingIcon: @Composable (() -> Unit)? = null,
    trailingIcon: @Composable (() -> Unit)? = null,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    keyboardActions: KeyboardActions = KeyboardActions.Default,
    onFocusChanged: (FocusState) -> Unit = {},
    isError: Boolean = false,
) {
    var focused by remember { mutableStateOf(false) }
    val bringIntoViewRequester = remember { BringIntoViewRequester() }
    val scope = rememberCoroutineScope()
    val borderColor = when {
        isError -> MaterialTheme.colorScheme.error
        focused -> PharmaTeal
        else -> Color(0xFFCBD5E1)
    }
    BasicTextField(
        value = value,
        onValueChange = onValueChange,
        singleLine = true,
        textStyle = MaterialTheme.typography.bodySmall.copy(color = PharmaSlate),
        cursorBrush = SolidColor(PharmaTeal),
        keyboardOptions = keyboardOptions,
        keyboardActions = keyboardActions,
        modifier = modifier
            .bringIntoViewRequester(bringIntoViewRequester)
            .height(SalesDenseFieldHeight)
            .onFocusChanged {
                focused = it.isFocused
                onFocusChanged(it)
                if (it.isFocused) {
                    scope.launch {
                        delay(320)
                        bringIntoViewRequester.bringIntoView()
                    }
                }
            },
        decorationBox = { inner ->
            Row(
                Modifier
                    .fillMaxSize()
                    .clip(RoundedCornerShape(6.dp))
                    .background(Color.White)
                    .border(1.dp, borderColor, RoundedCornerShape(6.dp))
                    .padding(horizontal = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (leadingIcon != null) {
                    leadingIcon()
                    Spacer(Modifier.width(6.dp))
                }
                Box(Modifier.weight(1f), contentAlignment = Alignment.CenterStart) {
                    if (value.isEmpty()) {
                        Text(
                            placeholder,
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                    inner()
                }
                if (trailingIcon != null) trailingIcon()
            }
        },
    )
}

/** Small label above a dense field — customer & bill details form. */
@Composable
internal fun SalesFormField(
    label: String,
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    placeholder: String = "",
    keyboardType: KeyboardType = KeyboardType.Text,
    isError: Boolean = false,
    trailingIcon: @Composable (() -> Unit)? = null,
) {
    Column(modifier) {
        Text(
            label,
            style = MaterialTheme.typography.labelSmall,
            color = if (isError) MaterialTheme.colorScheme.error else PharmaMuted,
            maxLines = 1,
        )
        Spacer(Modifier.height(1.dp))
        SalesDenseSearchField(
            value = value,
            onValueChange = onValueChange,
            placeholder = placeholder,
            modifier = Modifier.fillMaxWidth(),
            trailingIcon = trailingIcon,
            keyboardOptions = KeyboardOptions(keyboardType = keyboardType, imeAction = ImeAction.Next),
            isError = isError,
        )
    }
}

private val SalesSearchButtonWidth = 76.dp

/** Search field + fixed-width Search button; same layout on sales log and sales form. */
@Composable
internal fun SalesSearchRow(
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    onSearch: () -> Unit,
    modifier: Modifier = Modifier,
    leadingIcon: @Composable (() -> Unit)? = null,
    trailingIcon: @Composable (() -> Unit)? = null,
    onFocusChanged: (FocusState) -> Unit = {},
) {
    Row(
        modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        SalesDenseSearchField(
            value = value,
            onValueChange = onValueChange,
            placeholder = placeholder,
            modifier = Modifier.weight(1f),
            leadingIcon = leadingIcon,
            trailingIcon = trailingIcon,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(onSearch = { onSearch() }),
            onFocusChanged = onFocusChanged,
        )
        OutlinedButton(
            onClick = onSearch,
            modifier = Modifier
                .width(SalesSearchButtonWidth)
                .height(SalesDenseButtonHeight),
            shape = RoundedCornerShape(6.dp),
            contentPadding = PaddingValues(horizontal = 4.dp, vertical = 0.dp),
        ) {
            Text("Search", style = MaterialTheme.typography.labelSmall, maxLines = 1)
        }
    }
}

/** Pack/strip MRP from sales search API (same as web `sidebarProductMrp`). */
private fun productSearchMrp(p: JsonObject): Double {
    val mrp = p.amount("mrp")
    if (mrp > 0) return round2(mrp)
    val binding = p.amount("binding_mrp")
    if (binding > 0) return round2(binding)
    return 0.0
}

private fun formatPackExpiry(raw: String): String {
    if (raw.isBlank()) return "—"
    val parts = raw.take(10).split("-", "/")
    if (parts.size >= 2) {
        val mm = parts[1].take(2)
        val yy = parts[0].takeLast(2)
        return "$mm/$yy"
    }
    return raw
}

@Composable
fun SalesLogScreen(
    vm: AppViewModel,
    onNewSale: () -> Unit,
    onEditBill: (Int) -> Unit,
    onBack: () -> Unit,
) {
    val repo = remember { vm.sales() }
    val context = LocalContext.current
    var search by remember { mutableStateOf("") }
    var searchDraft by remember { mutableStateOf("") }
    var billMode by remember { mutableStateOf("") }
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var viewRow by remember { mutableStateOf<JsonObject?>(null) }
    var viewLoading by remember { mutableStateOf(false) }
    var payEdit by remember { mutableStateOf<JsonObject?>(null) }
    var payMode by remember { mutableStateOf("Cash") }
    var payAccountId by remember { mutableIntStateOf(0) }
    var payCash by remember { mutableStateOf("") }
    var payError by remember { mutableStateOf<String?>(null) }
    var paySaving by remember { mutableStateOf(false) }
    var accounts by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var toast by remember { mutableStateOf<String?>(null) }
    var whatsappSendingId by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val canView = vm.canView("SALES")
    val canEditSales = vm.canEdit("SALES")
    val canEditSavedBill = vm.hasFullAccess()

    fun reload() {
        scope.launch {
            loading = true
            runCatching {
                withContext(Dispatchers.IO) { repo.list(search = search, billMode = billMode) }
            }.onSuccess { rows = it.rows }.onFailure { error = it.message }
            loading = false
        }
    }

    fun openSaleFile(id: Int, bytes: ByteArray, fileName: String, mime: String) {
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
        }.onFailure { error = it.message ?: "Could not open file." }
    }

    fun printInvoice(id: Int, dual: Boolean = false, dotMatrix: Boolean = false) {
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    when {
                        dotMatrix -> repo.invoiceDotMatrixHtml(id)
                        dual -> repo.invoiceDualPdf(id)
                        else -> repo.invoicePdf(id)
                    }
                }
            }.onSuccess { bytes ->
                val inv = rows.find { it.jInt("id") == id }?.str("invoice_no")?.ifBlank { "sale-$id" } ?: "sale-$id"
                when {
                    // Dot-matrix stays HTML preview (not A4 Wi‑Fi PDF).
                    dotMatrix -> openSaleFile(id, bytes, "$inv-dotmatrix.html", "text/html")
                    dual -> {
                        PdfWifiPrint.printPdf(context, bytes, "Sale $inv (dual)")?.let { error = it }
                    }
                    else -> {
                        PdfWifiPrint.printPdf(context, bytes, "Sale $inv")?.let { error = it }
                    }
                }
            }.onFailure { error = it.message ?: "Could not load invoice for printing." }
        }
    }

    fun sendWhatsApp(id: Int, contact: String) {
        val phone = contact.filter { it.isDigit() }
        if (phone.length < 10) {
            toast = "Customer mobile is required for WhatsApp."
            return
        }
        whatsappSendingId = id
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.sendWhatsApp(id) } }
                .onSuccess { res ->
                    toast = res.str("message").ifBlank { "WhatsApp bill sent." }
                }
                .onFailure { error = it.message }
            whatsappSendingId = 0
        }
    }

    fun openView(id: Int) {
        viewLoading = true
        viewRow = null
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.get(id) } }
                .onSuccess { viewRow = it }
                .onFailure { error = it.message }
            viewLoading = false
        }
    }

    LaunchedEffect(Unit) {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.accounts() } }.onSuccess { accounts = it }
        }
    }
    LaunchedEffect(billMode, search) { reload() }

    val kpis = remember(rows) {
        var net = 0.0
        var paid = 0.0
        var balance = 0.0
        rows.forEach { r ->
            net += r.amount("net_amount")
            paid += r.amount("paid")
            balance += r.amount("balance")
        }
        listOf(
            "Bills" to rows.size.toString(),
            "Amount" to formatAmount2(net),
            "Paid" to formatAmount2(paid),
            "Balance" to formatAmount2(balance),
        )
    }

    Column(
        Modifier
            .fillMaxSize()
            .padding(CompactScreenPadding),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            PharmaOutlinedButton("Back", onClick = onBack, modifier = Modifier.heightIn(max = 34.dp))
            Column(Modifier.padding(start = 6.dp).weight(1f)) {
                Text("Sales report", style = MaterialTheme.typography.titleSmall)
                Text("Billing history", style = MaterialTheme.typography.labelSmall)
            }
            if (canView) {
                PharmaPrimaryButton("New sale", modifier = Modifier.heightIn(max = 34.dp), onClick = onNewSale)
            }
        }
        Spacer(Modifier.height(4.dp))
        LazyRowModes(billMode) { billMode = it }
        SalesSearchRow(
            value = searchDraft,
            onValueChange = { searchDraft = it },
            placeholder = "Bill, customer, contact…",
            onSearch = {
                search = searchDraft.trim()
                reload()
            },
            leadingIcon = {
                Icon(Icons.Default.Search, contentDescription = null, modifier = Modifier.size(16.dp), tint = PharmaTeal)
            },
        )
        Text(
            "Default list · search for older bills",
            style = MaterialTheme.typography.labelSmall,
            color = Color(0xFF64748B),
        )
        if (toast != null) {
            Spacer(Modifier.height(4.dp))
            Surface(color = Color(0xFFE0F2FE), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(toast!!, Modifier.weight(1f), style = MaterialTheme.typography.labelSmall)
                    TextButton(onClick = { toast = null }) { Text("OK", style = MaterialTheme.typography.labelSmall) }
                }
            }
        }
        if (error != null) {
            ErrorBanner(error!!) { error = null }
            Spacer(Modifier.height(4.dp))
        }
        SalesLogKpiStrip(kpis)
        Spacer(Modifier.height(4.dp))
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                loading -> LoadingBlock()
                rows.isEmpty() -> {
                    PharmaCard {
                        Text("No sales found.", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(24.dp))
                    }
                }
                else -> {
                    SalesLogScrollTable(
                        modifier = Modifier.fillMaxSize(),
                        rows = rows,
                        canEditSales = canEditSales,
                        canEditSavedBill = canEditSavedBill,
                        whatsappSendingId = whatsappSendingId,
                        onView = { openView(it) },
                        onEditBill = onEditBill,
                        onPayMode = { row ->
                            if (!canEditSales) {
                                toast = "You do not have permission to edit payment mode."
                            } else {
                                payEdit = row
                                payMode = salePayLabel(row)
                                payAccountId = row.jInt("account_id")
                                payCash = row.amount("cash_amount").takeIf { it > 0 }?.let { pNumText(it, false) }.orEmpty()
                                payError = null
                                // Full sale carries cash_amount / latest mode even when the log row is trimmed.
                                scope.launch {
                                    runCatching { withContext(Dispatchers.IO) { repo.get(row.jInt("id")) } }.onSuccess { full ->
                                        if (payEdit?.jInt("id") != row.jInt("id")) return@onSuccess
                                        payEdit = full
                                        payMode = salePayLabel(full)
                                        payAccountId = full.jInt("account_id")
                                        if (payMode == PAY_CASH_UPI) {
                                            payCash = full.amount("cash_amount").takeIf { it > 0 }?.let { pNumText(it, false) }.orEmpty()
                                        }
                                    }
                                }
                            }
                        },
                        onPrint = { printInvoice(it, dual = false, dotMatrix = false) },
                        onDualPrint = { printInvoice(it, dual = true, dotMatrix = false) },
                        onDotMatrix = { printInvoice(it, dual = false, dotMatrix = true) },
                        onWhatsApp = { id, phone -> sendWhatsApp(id, phone) },
                    )
                }
            }
        }
    }

    if (viewLoading) {
        LoadingBlock("Loading bill…")
    }
    viewRow?.let { bill ->
        SaleDetailSheet(
            bill = bill,
            canEditBill = canEditSavedBill &&
                bill.str("bill_mode").ifBlank { "retail" } == "retail",
            whatsappSending = whatsappSendingId == bill.jInt("id"),
            onEdit = {
                onEditBill(bill.jInt("id"))
                viewRow = null
            },
            onPrint = { printInvoice(bill.jInt("id")) },
            onDualPrint = { printInvoice(bill.jInt("id"), dual = true) },
            onDotMatrix = { printInvoice(bill.jInt("id"), dotMatrix = true) },
            onWhatsApp = { sendWhatsApp(bill.jInt("id"), bill.str("contact_no")) },
            onDismiss = { viewRow = null },
        )
    }
    payEdit?.let { sale ->
        val net = round2(sale.amount("net_amount"))
        PaymentModeSheet(
            invoiceNo = sale.str("invoice_no").ifBlank { "#${sale.jInt("id")}" },
            net = net,
            oldMode = sale.str("payment_mode").ifBlank { sale.str("cash_credit") },
            paymentMode = payMode,
            onMode = {
                payMode = it
                payError = null
                if (it != "Card") payAccountId = 0
                if (it != PAY_CASH_UPI) payCash = ""
            },
            accountId = payAccountId,
            onAccount = { payAccountId = it },
            accounts = accounts,
            cashAmount = payCash,
            onCashAmount = { payCash = it; payError = null },
            error = payError,
            saving = paySaving,
            onDismiss = { if (!paySaving) payEdit = null },
            onSave = save@{
                if (payMode == "Card" && payAccountId <= 0) {
                    payError = "Select a bank / card account for Card payments."
                    return@save
                }
                val cash = round2(payCash.toDoubleOrNull() ?: 0.0)
                if (payMode == PAY_CASH_UPI && (cash <= 0 || cash >= net)) {
                    payError = "Cash+UPI: enter the Cash amount — more than 0 and less than Net ${formatAmount2(net)}."
                    return@save
                }
                paySaving = true
                scope.launch {
                    runCatching {
                        withContext(Dispatchers.IO) {
                            repo.updatePaymentMode(
                                sale.jInt("id"),
                                payMode,
                                payAccountId.takeIf { payMode == "Card" && it > 0 },
                                cash.takeIf { payMode == PAY_CASH_UPI },
                            )
                        }
                    }.onSuccess { res ->
                        toast = "Payment mode updated to ${payModeLabel(res.str("payment_mode").ifBlank { payMode })}. " +
                            "Paid ${formatAmount2(res.amount("paid"))} · Balance ${formatAmount2(res.amount("balance"))}."
                        payEdit = null
                        reload()
                    }.onFailure { payError = it.message ?: "Failed to update payment mode." }
                    paySaving = false
                }
            },
        )
    }
}

private fun salePayLabel(row: JsonObject): String =
    payModeLabel(row.str("payment_mode").ifBlank { row.str("cash_credit") })

/** API payment_mode → UI label (web sales-list payLabel). */
internal fun payModeLabel(raw: String): String = when (raw.trim().lowercase()) {
    "cash+upi" -> "Cash+UPI"
    "upi" -> "UPI"
    "card" -> "Card"
    "credit" -> "Credit"
    else -> "Cash"
}

internal const val PAY_CASH_UPI = "Cash+UPI"
private val SalePaymentModes = listOf("Cash", "Card", "UPI", "Credit")
private val RetailPaymentModes = SalePaymentModes + PAY_CASH_UPI

private object SalesLogCols {
    val billNo = 88.dp
    val date = 78.dp
    val customer = 120.dp
    val contact = 88.dp
    val payMode = 62.dp
    val amount = 72.dp
    val paid = 68.dp
    val balance = 68.dp
    val view = 36.dp
    val edit = 44.dp
    val print = 108.dp
    val wa = 36.dp
    val gap = 4.dp
}

private object SaleViewHeaderCols {
    val billNo = 72.dp
    val date = 76.dp
    val customer = 100.dp
    val contact = 80.dp
    val email = 100.dp
    val doctor = 80.dp
    val pay = 64.dp
}

private object SaleViewLineCols {
    val product = 140.dp
    val qty = 44.dp
    val rate = 56.dp
    val mrp = 56.dp
    val disc = 48.dp
    val net = 64.dp
}

@Composable
private fun SalesLogScrollTable(
    rows: List<JsonObject>,
    canEditSales: Boolean,
    canEditSavedBill: Boolean,
    whatsappSendingId: Int,
    onView: (Int) -> Unit,
    onEditBill: (Int) -> Unit,
    onPayMode: (JsonObject) -> Unit,
    onPrint: (Int) -> Unit,
    onDualPrint: (Int) -> Unit,
    onDotMatrix: (Int) -> Unit,
    onWhatsApp: (Int, String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val scroll = rememberScrollState()
    PharmaCard(modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            Text(
                "Swipe horizontally for all columns",
                style = MaterialTheme.typography.labelSmall,
                color = Color(0xFF64748B),
                modifier = Modifier.padding(bottom = 4.dp),
            )
            Row(
                Modifier
                    .fillMaxWidth()
                    .horizontalScroll(scroll)
                    .background(BillGridHeaderBg),
                horizontalArrangement = Arrangement.spacedBy(SalesLogCols.gap),
            ) {
                BillGridTh("BILL NO", SalesLogCols.billNo)
                BillGridTh("ORDER DATE", SalesLogCols.date)
                BillGridTh("CUSTOMER", SalesLogCols.customer)
                BillGridTh("CONTACT", SalesLogCols.contact)
                BillGridTh("C/D", SalesLogCols.payMode)
                BillGridTh("AMOUNT", SalesLogCols.amount, alignEnd = true)
                BillGridTh("PAID", SalesLogCols.paid, alignEnd = true)
                BillGridTh("BALANCE", SalesLogCols.balance, alignEnd = true)
                BillGridTh("VIEW", SalesLogCols.view)
                BillGridTh("EDIT", SalesLogCols.edit)
                BillGridTh("PRINT", SalesLogCols.print)
                BillGridTh("WA", SalesLogCols.wa)
            }
            HorizontalDivider(color = PharmaBorder)
            LazyColumn(Modifier.weight(1f)) {
                items(rows.size) { index ->
                    val row = rows[index]
                    SalesLogDataRow(
                        row = row,
                        scroll = scroll,
                        canEditSales = canEditSales,
                        canEditSavedBill = canEditSavedBill,
                        whatsappSending = whatsappSendingId == row.jInt("id"),
                        onView = { onView(row.jInt("id")) },
                        onEditBill = { onEditBill(row.jInt("id")) },
                        onPayMode = { onPayMode(row) },
                        onPrint = { onPrint(row.jInt("id")) },
                        onDualPrint = { onDualPrint(row.jInt("id")) },
                        onDotMatrix = { onDotMatrix(row.jInt("id")) },
                        onWhatsApp = { onWhatsApp(row.jInt("id"), row.str("contact_no")) },
                    )
                    if (index < rows.lastIndex) {
                        HorizontalDivider(color = PharmaBorder.copy(alpha = 0.45f))
                    }
                }
            }
        }
    }
}

@Composable
private fun SalesLogDataRow(
    row: JsonObject,
    scroll: ScrollState,
    canEditSales: Boolean,
    canEditSavedBill: Boolean,
    whatsappSending: Boolean,
    onView: () -> Unit,
    onEditBill: () -> Unit,
    onPayMode: () -> Unit,
    onPrint: () -> Unit,
    onDualPrint: () -> Unit,
    onDotMatrix: () -> Unit,
    onWhatsApp: () -> Unit,
) {
    val retail = row.str("bill_mode").ifBlank { "retail" } == "retail"
    val locked = row.jInt("is_locked") == 1
    val balance = row.amount("balance")
    // Full bill edit on mobile supports retail bills only; admins get payment-mode edit on wholesale/IPD.
    val canFullBillEdit = canEditSavedBill && retail
    val canPayModeEdit = !canFullBillEdit && canEditSales
    Row(
        Modifier
            .fillMaxWidth()
            .horizontalScroll(scroll)
            .heightIn(min = 40.dp)
            .padding(vertical = 2.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(SalesLogCols.gap),
    ) {
        Row(Modifier.width(SalesLogCols.billNo), verticalAlignment = Alignment.CenterVertically) {
            Text(
                row.str("invoice_no").ifBlank { row.str("order_no") },
                style = MaterialTheme.typography.labelSmall,
                fontWeight = FontWeight.SemiBold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f, fill = false),
            )
            if (locked) {
                Icon(
                    Icons.Default.Lock,
                    contentDescription = "Locked bill",
                    modifier = Modifier.size(14.dp),
                    tint = Color(0xFFD97706),
                )
            }
        }
        BillGridCellText(formatSaleDate(row.str("invoice_date")), SalesLogCols.date)
        BillGridCellText(
            row.str("patient_name").ifBlank { row.str("customer_name") }.ifBlank { "Walk-in" },
            SalesLogCols.customer,
        )
        BillGridCellText(row.str("contact_no").ifBlank { "—" }, SalesLogCols.contact)
        BillGridCellText(salePayLabel(row), SalesLogCols.payMode)
        BillGridCellText(
            formatAmount2(row.amount("net_amount")),
            SalesLogCols.amount,
            alignEnd = true,
            fontWeight = FontWeight.SemiBold,
        )
        BillGridCellText(formatAmount2(row.amount("paid")), SalesLogCols.paid, alignEnd = true)
        BillGridCellText(
            formatAmount2(balance),
            SalesLogCols.balance,
            alignEnd = true,
            color = if (balance > 0) MaterialTheme.colorScheme.error else Color(0xFF059669),
        )
        SalesLogIconAction(
            onClick = onView,
            contentDescription = "View bill",
            modifier = Modifier.width(SalesLogCols.view),
        ) {
            Icon(Icons.Default.Visibility, null, Modifier.size(18.dp), tint = Color(0xFF0284C7))
        }
        Box(Modifier.width(SalesLogCols.edit), contentAlignment = Alignment.Center) {
            when {
                canFullBillEdit -> {
                    SalesLogIconAction(onClick = onEditBill, contentDescription = "Edit bill") {
                        Icon(Icons.Default.Edit, null, Modifier.size(18.dp), tint = Color(0xFFD97706))
                    }
                }
                canPayModeEdit -> {
                    SalesLogIconAction(onClick = onPayMode, contentDescription = "Edit payment mode") {
                        Icon(
                            Icons.Default.AccountBalanceWallet,
                            null,
                            Modifier.size(18.dp),
                            tint = Color(0xFFD97706),
                        )
                    }
                }
                else -> Text("—", style = MaterialTheme.typography.labelSmall)
            }
        }
        Row(
            Modifier.width(SalesLogCols.print),
            horizontalArrangement = Arrangement.spacedBy(0.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            SalesLogIconAction(onClick = onPrint, contentDescription = "Print") {
                Icon(Icons.Default.Print, null, Modifier.size(18.dp), tint = Color(0xFF64748B))
            }
            SalesLogIconAction(onClick = onDualPrint, contentDescription = "Dual print") {
                Icon(Icons.Default.ContentCopy, null, Modifier.size(17.dp), tint = Color(0xFF0284C7))
            }
            SalesLogIconAction(onClick = onDotMatrix, contentDescription = "Dot matrix print") {
                Icon(Icons.Default.Print, null, Modifier.size(18.dp), tint = Color(0xFF0F172A))
            }
        }
        Box(Modifier.width(SalesLogCols.wa), contentAlignment = Alignment.Center) {
            if (whatsappSending) {
                CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
            } else {
                SalesLogIconAction(onClick = onWhatsApp, contentDescription = "Send WhatsApp") {
                    Icon(
                        painterResource(R.drawable.ic_whatsapp),
                        contentDescription = null,
                        modifier = Modifier.size(20.dp),
                        tint = Color.Unspecified,
                    )
                }
            }
        }
    }
}

@Composable
internal fun SalesLogIconAction(
    onClick: () -> Unit,
    contentDescription: String,
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit,
) {
    IconButton(
        onClick = onClick,
        modifier = modifier.size(32.dp),
    ) {
        Box(contentAlignment = Alignment.Center) {
            content()
        }
    }
}

@Composable
private fun SalesLogKpiStrip(kpis: List<Pair<String, String>>) {
    Column(
        modifier = Modifier.fillMaxWidth(),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            kpis.take(2).forEach { (label, value) ->
                SalesLogKpiChip(label, value, Modifier.weight(1f))
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            kpis.drop(2).forEach { (label, value) ->
                SalesLogKpiChip(label, value, Modifier.weight(1f))
            }
        }
    }
}

@Composable
internal fun SalesLogKpiChip(label: String, value: String, modifier: Modifier = Modifier) {
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = Color.White,
        border = BorderStroke(1.dp, PharmaBorder),
        modifier = modifier,
    ) {
        Column(Modifier.padding(horizontal = 8.dp, vertical = 4.dp)) {
            Text(
                label,
                style = MaterialTheme.typography.labelSmall,
                fontSize = MaterialTheme.typography.labelSmall.fontSize * 0.92f,
                maxLines = 1,
            )
            Text(
                value,
                style = MaterialTheme.typography.titleSmall,
                fontSize = MaterialTheme.typography.titleSmall.fontSize * 0.88f,
                color = PharmaTeal,
                fontWeight = FontWeight.SemiBold,
                maxLines = 1,
            )
        }
    }
}

@Composable
private fun LazyRowModes(selected: String, onSelect: (String) -> Unit) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        items(listOf("" to "All", "retail" to "Retail", "wholesale" to "Wholesale", "ipd" to "IPD")) { (value, label) ->
            FilterChip(
                selected = selected == value,
                onClick = { onSelect(value) },
                label = { Text(label, style = MaterialTheme.typography.labelSmall) },
            )
        }
    }
}

@Composable
private fun SalesKpiGrid(kpis: List<Pair<String, String>>) {
    LazyVerticalGrid(
        columns = GridCells.Fixed(2),
        modifier = Modifier.heightIn(max = 80.dp),
        userScrollEnabled = false,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        items(kpis.size) { i ->
            val (l, v) = kpis[i]
            KpiTile(l, v)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SaleDetailSheet(
    bill: JsonObject,
    canEditBill: Boolean,
    whatsappSending: Boolean,
    onEdit: () -> Unit,
    onPrint: () -> Unit,
    onDualPrint: () -> Unit,
    onDotMatrix: () -> Unit,
    onWhatsApp: () -> Unit,
    onDismiss: () -> Unit,
) {
    val items = bill.arr("items")
    val headerScroll = rememberScrollState()
    val lineScroll = rememberScrollState()
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(
            Modifier
                .padding(12.dp)
                .verticalScroll(rememberScrollState()),
        ) {
            Text("Sales bill", style = MaterialTheme.typography.titleMedium)
            Text(
                "Swipe tables horizontally for all columns",
                style = MaterialTheme.typography.labelSmall,
                color = Color(0xFF64748B),
            )
            Spacer(Modifier.height(8.dp))
            PharmaCard {
                Row(Modifier.horizontalScroll(headerScroll)) {
                    Column {
                        Row(
                            Modifier.background(BillGridHeaderBg),
                            horizontalArrangement = Arrangement.spacedBy(4.dp),
                        ) {
                            BillGridTh("BILL NO", SaleViewHeaderCols.billNo)
                            BillGridTh("BILL DATE", SaleViewHeaderCols.date)
                            BillGridTh("CUSTOMER", SaleViewHeaderCols.customer)
                            BillGridTh("CONTACT", SaleViewHeaderCols.contact)
                            BillGridTh("EMAIL", SaleViewHeaderCols.email)
                            BillGridTh("DOCTOR", SaleViewHeaderCols.doctor)
                            BillGridTh("PAY MODE", SaleViewHeaderCols.pay)
                        }
                        HorizontalDivider(color = PharmaBorder)
                        Row(
                            Modifier.padding(vertical = 4.dp),
                            horizontalArrangement = Arrangement.spacedBy(4.dp),
                        ) {
                            BillGridCellText(bill.str("invoice_no"), SaleViewHeaderCols.billNo)
                            BillGridCellText(formatSaleDate(bill.str("invoice_date")), SaleViewHeaderCols.date)
                            BillGridCellText(
                                bill.str("patient_name").ifBlank { bill.str("customer_name") },
                                SaleViewHeaderCols.customer,
                            )
                            BillGridCellText(bill.str("contact_no").ifBlank { "—" }, SaleViewHeaderCols.contact)
                            BillGridCellText(bill.str("email").ifBlank { "—" }, SaleViewHeaderCols.email)
                            BillGridCellText(bill.str("doctor_name").ifBlank { "—" }, SaleViewHeaderCols.doctor)
                            BillGridCellText(salePayLabel(bill), SaleViewHeaderCols.pay)
                        }
                    }
                }
            }
            Spacer(Modifier.height(8.dp))
            PharmaCard {
                Row(Modifier.horizontalScroll(lineScroll)) {
                    Column {
                        Row(
                            Modifier.background(BillGridHeaderBg),
                            horizontalArrangement = Arrangement.spacedBy(4.dp),
                        ) {
                            BillGridTh("PRODUCT", SaleViewLineCols.product)
                            BillGridTh("QTY", SaleViewLineCols.qty, alignEnd = true)
                            BillGridTh("RATE", SaleViewLineCols.rate, alignEnd = true)
                            BillGridTh("MRP", SaleViewLineCols.mrp, alignEnd = true)
                            BillGridTh("DISC%", SaleViewLineCols.disc, alignEnd = true)
                            BillGridTh("NET", SaleViewLineCols.net, alignEnd = true)
                        }
                        HorizontalDivider(color = PharmaBorder)
                        if (items.size() == 0) {
                            Text("No line items", Modifier.padding(12.dp), style = MaterialTheme.typography.bodySmall)
                        } else {
                            for (i in 0 until items.size()) {
                                val el = items.get(i)
                                if (!el.isJsonObject) continue
                                val line = el.asJsonObject
                                val rate = line.amount("display_rate").takeIf { it > 0 } ?: line.amount("rate")
                                val mrp = line.amount("display_mrp").takeIf { it > 0 } ?: line.amount("mrp")
                                val net = line.amount("line_net").takeIf { it > 0 }
                                    ?: line.amount("net_amount").takeIf { it > 0 }
                                    ?: rate * line.jInt("quantity") * (1 - line.amount("discount_pct") / 100.0)
                                Row(
                                    Modifier.padding(vertical = 3.dp),
                                    horizontalArrangement = Arrangement.spacedBy(4.dp),
                                ) {
                                    BillGridCellText(line.str("product_name"), SaleViewLineCols.product)
                                    BillGridCellText(line.jInt("quantity").toString(), SaleViewLineCols.qty, alignEnd = true)
                                    BillGridCellText(formatAmount2(rate), SaleViewLineCols.rate, alignEnd = true)
                                    BillGridCellText(formatAmount2(mrp), SaleViewLineCols.mrp, alignEnd = true)
                                    BillGridCellText(formatAmount2(line.amount("discount_pct")), SaleViewLineCols.disc, alignEnd = true)
                                    BillGridCellText(
                                        formatAmount2(net),
                                        SaleViewLineCols.net,
                                        alignEnd = true,
                                        fontWeight = FontWeight.SemiBold,
                                    )
                                }
                                if (i < items.size() - 1) {
                                    HorizontalDivider(color = PharmaBorder.copy(alpha = 0.4f))
                                }
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(8.dp))
            Text(
                buildString {
                    val ro = bill.amount("round_off")
                    if (ro != 0.0) append("Round off ${if (ro > 0) "+" else ""}${formatAmount2(ro)} · ")
                    append("Net ${formatAmount2(bill.amount("net_amount"))} · GST ${formatAmount2(bill.amount("gst_amount"))} · Paid ${formatAmount2(bill.amount("paid"))} · Balance ${formatAmount2(bill.amount("balance"))}")
                    if (salePayLabel(bill) == PAY_CASH_UPI) {
                        append(" · Cash ${formatAmount2(bill.amount("cash_amount"))} + UPI ${formatAmount2(bill.amount("upi_amount"))}")
                    }
                },
                style = MaterialTheme.typography.labelSmall,
            )
            Spacer(Modifier.height(8.dp))
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(4.dp, Alignment.CenterHorizontally),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (canEditBill) {
                    SalesLogIconAction(onClick = onEdit, contentDescription = "Edit bill") {
                        Icon(Icons.Default.Edit, null, Modifier.size(20.dp), tint = Color(0xFFD97706))
                    }
                }
                if (whatsappSending) {
                    CircularProgressIndicator(Modifier.size(28.dp), strokeWidth = 2.dp)
                } else {
                    SalesLogIconAction(onClick = onWhatsApp, contentDescription = "WhatsApp") {
                        Icon(
                            painterResource(R.drawable.ic_whatsapp),
                            null,
                            Modifier.size(22.dp),
                            tint = Color.Unspecified,
                        )
                    }
                }
                SalesLogIconAction(onClick = onPrint, contentDescription = "Print") {
                    Icon(Icons.Default.Print, null, Modifier.size(20.dp), tint = Color(0xFF64748B))
                }
                SalesLogIconAction(onClick = onDualPrint, contentDescription = "Dual print") {
                    Icon(Icons.Default.ContentCopy, null, Modifier.size(20.dp), tint = Color(0xFF0284C7))
                }
                SalesLogIconAction(onClick = onDotMatrix, contentDescription = "Dot matrix print") {
                    Icon(Icons.Default.Print, null, Modifier.size(20.dp), tint = Color(0xFF0F172A))
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PaymentModeSheet(
    invoiceNo: String,
    net: Double,
    oldMode: String,
    paymentMode: String,
    onMode: (String) -> Unit,
    accountId: Int,
    onAccount: (Int) -> Unit,
    accounts: List<JsonObject>,
    cashAmount: String,
    onCashAmount: (String) -> Unit,
    error: String?,
    saving: Boolean,
    onDismiss: () -> Unit,
    onSave: () -> Unit,
) {
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(
            Modifier
                .padding(horizontal = 12.dp)
                .verticalScroll(rememberScrollState())
                .imePadding(),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text("Edit payment mode", style = MaterialTheme.typography.titleMedium)
            Text(
                "Bill $invoiceNo · Net ${formatAmount2(net)} · now ${payModeLabel(oldMode)}. Only the payment mode changes — bill lines stay as saved.",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
            PayModeChips(RetailPaymentModes, paymentMode, onMode)
            if (paymentMode == "Card") {
                Text("Bank / card account *", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                if (accounts.isEmpty()) {
                    Text("No bank accounts found. Add one in Masters → Accounts.", style = MaterialTheme.typography.labelSmall, color = Color(0xFFB45309))
                }
                accounts.forEach { acc ->
                    val sel = acc.jInt("id") == accountId
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(6.dp))
                            .background(if (sel) Color(0xFFE6F4F1) else Color.White)
                            .border(1.dp, if (sel) PharmaTeal else PharmaBorder, RoundedCornerShape(6.dp))
                            .clickable { onAccount(acc.jInt("id")) }
                            .padding(horizontal = 8.dp, vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(acc.str("name"), Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = PharmaSlate)
                        if (sel) Icon(Icons.Default.Check, null, Modifier.size(16.dp), tint = PharmaTeal)
                    }
                }
            }
            if (paymentMode == PAY_CASH_UPI) {
                CashUpiSplit(net = net, cashAmount = cashAmount, onCashAmount = onCashAmount)
            }
            val preview = when {
                paymentMode == "Credit" && oldMode.equals("credit", true) -> "Stays on credit — paid / balance unchanged."
                paymentMode == "Credit" -> "Bill moves to credit: Balance ${formatAmount2(net)}."
                else -> "Paid ${formatAmount2(net)} · Balance 0.00"
            }
            Text(preview, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            error?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.error) }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                PharmaOutlinedButton("Cancel", onClick = onDismiss, modifier = Modifier.weight(1f))
                PharmaPrimaryButton(if (saving) "Saving…" else "Save", modifier = Modifier.weight(1f), enabled = !saving, onClick = onSave)
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

/** Segmented payment-mode buttons; labels never truncate (Cash+UPI shrinks to fit). */
@Composable
private fun PayModeChips(modes: List<String>, selected: String, onSelect: (String) -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        modes.forEach { m ->
            val sel = selected == m
            Box(
                Modifier
                    .weight(if (m == PAY_CASH_UPI) 1.35f else 1f)
                    .height(32.dp)
                    .clip(RoundedCornerShape(6.dp))
                    .background(if (sel) PharmaTeal else Color.White)
                    .border(1.dp, if (sel) PharmaTeal else Color(0xFFCBD5E1), RoundedCornerShape(6.dp))
                    .clickable { onSelect(m) },
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    m,
                    style = MaterialTheme.typography.labelSmall,
                    fontWeight = if (sel) FontWeight.SemiBold else FontWeight.Normal,
                    color = if (sel) Color.White else PharmaSlate,
                    maxLines = 1,
                    softWrap = false,
                )
            }
        }
    }
}

/** Cash+UPI: user enters Cash, UPI = Net − Cash (web billing / sales-list). */
@Composable
private fun CashUpiSplit(net: Double, cashAmount: String, onCashAmount: (String) -> Unit) {
    val cash = cashAmount.toDoubleOrNull() ?: 0.0
    val upi = (round2(net - cash)).coerceAtLeast(0.0)
    val bad = cashAmount.isNotBlank() && (cash <= 0 || cash >= net)
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
        SalesFormField(
            label = "Cash amount *",
            value = cashAmount,
            onValueChange = { v -> onCashAmount(v.filter { it.isDigit() || it == '.' }.take(10)) },
            keyboardType = KeyboardType.Decimal,
            placeholder = "0.00",
            isError = bad,
            modifier = Modifier.weight(1f),
        )
        Column(Modifier.weight(1f)) {
            Text("UPI (Net − Cash)", style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1)
            Spacer(Modifier.height(1.dp))
            Box(
                Modifier
                    .fillMaxWidth()
                    .height(34.dp)
                    .clip(RoundedCornerShape(6.dp))
                    .background(Color(0xFFF1F5F9))
                    .border(1.dp, PharmaBorder, RoundedCornerShape(6.dp))
                    .padding(horizontal = 8.dp),
                contentAlignment = Alignment.CenterStart,
            ) {
                Text(formatAmount2(upi), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaTeal)
            }
        }
    }
    Text(
        if (bad) "Cash must be more than 0 and less than Net ${formatAmount2(net)}."
        else "Enter the cash received; the rest is recorded as UPI.",
        style = MaterialTheme.typography.labelSmall,
        color = if (bad) MaterialTheme.colorScheme.error else PharmaMuted,
    )
}

private enum class ProductSearchMode(val label: String, val shortLabel: String) {
    Name("Product name", "Name"),
    Generic("Generic molecule", "Generic"),
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun SalesBillingScreen(
    vm: AppViewModel,
    editId: Int?,
    onBack: () -> Unit,
    onSaved: (String) -> Unit,
) {
    val repo = remember { vm.sales() }
    val isEdit = editId != null && editId > 0
    val counters = remember { mutableStateListOf(*SaleCounters.toTypedArray()) }
    var activeCounter by remember { mutableStateOf(if (isEdit) "EDIT" else SaleCounters.first()) }
    val counterBills = remember { HashMap<String, CounterBill>() }
    var counterEpoch by remember { mutableIntStateOf(0) }
    val bill = remember(activeCounter, counterEpoch) {
        counterBills.getOrPut(activeCounter) { CounterBill(activeCounter == CASH_COUNTER) }
    }
    val isCashCounter = !isEdit && activeCounter == CASH_COUNTER
    val businessType by vm.businessType.collectAsState()
    /** Top Retail ↔ Wholesale bar (hidden on Cash Counter / edit — web parity). */
    val showBillModeSwitch = !isEdit && !isCashCounter
    var billMode by bill.billMode
    var saleType by bill.saleType
    var rateType by bill.rateType
    var cdDiscPct by bill.cdDiscPct
    var billDate by bill.billDate
    var customerName by bill.customerName
    var customerId by bill.customerId
    var phone by bill.phone
    var email by bill.email
    var address by bill.address
    var doctor by bill.doctor
    var narration by bill.narration
    var billDiscAmt by bill.billDiscAmt
    var paymentMode by bill.paymentMode
    var accountId by bill.accountId
    var cashAmount by bill.cashAmount
    var addCounterOpen by remember { mutableStateOf(false) }
    var savedNote by remember { mutableStateOf<String?>(null) }
    var rateMargins by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var query by remember { mutableStateOf("") }
    var searchMode by remember { mutableStateOf(ProductSearchMode.Name) }
    var searchModeExpanded by remember { mutableStateOf(false) }
    var genericId by remember { mutableIntStateOf(0) }
    var genericLabel by remember { mutableStateOf("Select generic…") }
    var genericExpanded by remember { mutableStateOf(false) }
    var generics by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var generalStockOnly by remember { mutableStateOf(false) }
    var hits by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var searchLoading by remember { mutableStateOf(false) }
    var lines by bill.lines
    var accounts by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(false) }
    var initLoading by remember { mutableStateOf(isEdit) }
    var error by remember { mutableStateOf<String?>(null) }
    var customerExpanded by bill.customerExpanded
    var phoneMatches by bill.phoneMatches
    var patientPickExpanded by remember { mutableStateOf(false) }
    var lookingUp by remember { mutableStateOf(false) }
    var lookupNote by bill.lookupNote
    var accountExpanded by remember { mutableStateOf(false) }
    var addProductExpanded by remember { mutableStateOf(true) }
    var catalogListExpanded by remember { mutableStateOf(true) }
    var searchFocused by remember { mutableStateOf(false) }
    var batchPick by remember { mutableStateOf<BatchPickState?>(null) }
    var lineBatchPick by remember { mutableStateOf<SaleLineDraft?>(null) }
    val scope = rememberCoroutineScope()
    val agtRepo = remember { vm.agt() }
    val canUseAgt = !isEdit && vm.canUseAgt()

    fun applyBillMode(mode: String) {
        if (isCashCounter && mode != "retail") return
        billMode = mode
        if (mode == "wholesale" && paymentMode == PAY_CASH_UPI) {
            paymentMode = "Cash"
            cashAmount = ""
        }
        if (mode == "wholesale" && saleType == "cash") saleType = "regular"
        if (mode == "wholesale") {
            lines = lines.map { repriceWholesaleLine(it, rateType, rateMargins) }
        } else {
            lines = lines.map { l ->
                val unitRate = l.unitMrp.takeIf { it > 0 } ?: (l.packMrp / l.unitsPerPack.coerceAtLeast(1))
                l.copy(unitType = "unit", rate = unitRate.coerceAtLeast(0.0), freeQty = 0)
            }
        }
    }

    fun applyRateType(rt: String) {
        rateType = rt
        if (billMode == "wholesale") {
            lines = lines.map { repriceWholesaleLine(it, rt, rateMargins) }
        }
    }

    // Default mode from client business_type (web billing parity).
    LaunchedEffect(businessType, isEdit, isCashCounter) {
        if (isEdit || isCashCounter) return@LaunchedEffect
        when {
            businessType.equals("wholesale", true) && billMode != "wholesale" -> applyBillMode("wholesale")
            businessType.equals("retail", true) && billMode == "wholesale" -> applyBillMode("retail")
        }
    }

    // Rate margins for PTR/PTS + default CD% (web loadWholesaleDefaults).
    LaunchedEffect(billMode) {
        if (billMode != "wholesale") return@LaunchedEffect
        runCatching {
            withContext(Dispatchers.IO) { vm.crud.list("rate-margins", perPage = 50).rows }
        }.onSuccess { rows ->
            rateMargins = rows
            val std = marginForCategory(rows, "Allopathy")?.amount("std_discount") ?: 0.0
            if (std > 0 && cdDiscPct <= 0) cdDiscPct = std
            if (lines.isNotEmpty()) {
                lines = lines.map { repriceWholesaleLine(it, rateType, rows) }
            }
        }
    }
    var agtSheetOpen by remember { mutableStateOf(false) }
    var agtSheetLoading by remember { mutableStateOf(false) }
    var agtSheetOrders by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var agtSelected by remember { mutableStateOf<JsonObject?>(null) }
    var agtAdding by remember { mutableStateOf(false) }
    var agtConfirm by remember { mutableStateOf<JsonObject?>(null) }
    var agtRx by remember { mutableStateOf<Pair<String, String>?>(null) }
    var agtPending by bill.agtPending

    val billDiscount = billDiscAmt.toDoubleOrNull() ?: 0.0
    val isWholesale = billMode == "wholesale"
    val netBeforeRound = remember(lines, billDiscount, billMode, cdDiscPct) {
        round2(
            if (billMode == "wholesale") wholesaleBillNet(lines, cdDiscPct)
            else saleBillNet(lines, billDiscount),
        )
    }
    val netTotal = roundRupee(netBeforeRound)
    val roundOff = round2(netTotal - netBeforeRound)
    val gstEstimate = remember(lines, billMode, cdDiscPct) {
        if (billMode == "wholesale") lines.sumOf { lineGstWholesale(it, cdDiscPct) }
        else lines.sumOf { lineGstRetail(it) }
    }
    val payModes = if (billMode == "wholesale") SalePaymentModes else RetailPaymentModes
    val patientRequired = billMode == "wholesale" || saleType == "regular"

    if (isEdit && !vm.hasFullAccess()) {
        Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            ErrorBanner("Only admins (HO / branch admin) can edit the full bill. Use Pay in the sales log to change the payment mode.") { onBack() }
            PharmaOutlinedButton("Back to sales", onClick = onBack)
        }
        return
    }

    fun switchCounter(c: String) {
        if (c == activeCounter) return
        activeCounter = c
        error = null
        savedNote = null
        batchPick = null
        lineBatchPick = null
        patientPickExpanded = false
    }

    LaunchedEffect(Unit) {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.accounts() } }.onSuccess { accounts = it }
            runCatching { withContext(Dispatchers.IO) { vm.crud.list("generics", perPage = 250) } }
                .onSuccess { generics = it.rows }
        }
    }

    LaunchedEffect(editId) {
        if (editId == null || editId <= 0) return@LaunchedEffect
        initLoading = true
        try {
            runCatching { withContext(Dispatchers.IO) { repo.get(editId) } }.onSuccess { sale ->
                val mode = sale.str("bill_mode").ifBlank { "retail" }
                if (mode != "retail") {
                    error = "Only retail bills can be edited here. Use Pay for payment mode on other types."
                    billMode = mode
                } else {
                    billMode = "retail"
                }
                saleType = sale.str("sale_type").ifBlank { "regular" }.lowercase()
                billDate = sale.str("invoice_date").ifBlank { billDate }
                customerName = sale.str("customer_name").ifBlank { sale.str("patient_name") }
                customerId = sale.jInt("customer_id")
                phone = sale.str("contact_no")
                email = sale.str("email")
                address = sale.str("address")
                doctor = sale.str("doctor_name")
                narration = sale.str("narration")
                billDiscAmt = sale.amount("bill_discount_amount").takeIf { it > 0 }?.toString()
                    ?: sale.amount("bill_bulk_discount_amount").toString()
                paymentMode = payModeLabel(sale.str("payment_mode"))
                accountId = sale.jInt("account_id")
                cashAmount = sale.amount("cash_amount").takeIf { paymentMode == PAY_CASH_UPI && it > 0 }?.let { pNumText(it, false) }.orEmpty()
                customerExpanded = true
                val arr = sale.arr("items")
                lines = (0 until arr.size()).mapNotNull { i ->
                    val el = arr.get(i)
                    if (!el.isJsonObject) return@mapNotNull null
                    val it = el.asJsonObject
                    val bindingId = it.jInt("product_binding_id")
                    if (bindingId <= 0) return@mapNotNull null
                    SaleLineDraft(
                        bindingId = bindingId,
                        productName = it.str("product_name"),
                        schedule = it.str("schedule_category").ifBlank { "OTC" },
                        batchId = it.jInt("batch_id"),
                        batchNo = it.str("batch_no"),
                        expiry = it.str("expiry_date"),
                        rate = it.amount("rate"),
                        gstRate = it.amount("gst_rate"),
                        hsn = it.str("hsn_code"),
                        unitsPerPack = it.jInt("units_per_pack").coerceAtLeast(1),
                        qty = it.jInt("quantity").coerceAtLeast(1),
                        discountPct = it.amount("discount_pct"),
                        discAmt = it.amount("discount_amount"),
                        unitType = it.str("unit_type").ifBlank { "unit" },
                        mfg = it.str("manufacturer_short").ifBlank { it.str("manufacturer") },
                        pack = it.str("pack_size"),
                    )
                }
            }.onFailure { error = it.message }
        } finally {
            initLoading = false
        }
    }

    fun filterProductHits(list: List<JsonObject>): List<JsonObject> =
        list.filter { p ->
            if (!generalStockOnly && !isCashCounter) return@filter true
            val sch = p.str("schedule_category").uppercase()
            sch !in CashCounterHiddenSchedules
        }

    // Same as web billing: empty q loads full catalog; typing narrows via API.
    LaunchedEffect(query, searchMode, genericId, generalStockOnly, isCashCounter) {
        val q = query.trim()
        val gid = if (searchMode == ProductSearchMode.Generic && genericId > 0) genericId else 0
        searchLoading = true
        try {
            if (q.isNotEmpty()) delay(200)
            val snapshotQ = q
            runCatching { withContext(Dispatchers.IO) { repo.searchProducts(q, gid) } }
                .onSuccess { list ->
                    if (query.trim() != snapshotQ) return@onSuccess
                    hits = filterProductHits(list)
                }
                .onFailure {
                    if (query.trim() == snapshotQ) hits = emptyList()
                }
        } finally {
            searchLoading = false
        }
    }

    fun updateLine(key: Long, transform: (SaleLineDraft) -> SaleLineDraft) {
        lines = lines.map { if (it.lineKey == key) transform(it) else it }
    }

    fun removeLine(key: Long) {
        lines = lines.filter { it.lineKey != key }
    }

    fun liveBill(): CounterBill =
        counterBills.getOrPut(activeCounter) { CounterBill(activeCounter == CASH_COUNTER) }

    fun normalCounters(): List<String> = counters.filter { isNormalSaleCounter(it) }

    /** Send one Cash Counter line to Counter 1 / 2 / 3 (or any extra normal counter). Stay on Cash Counter. */
    fun moveLineToCounter(lineKey: Long, destName: String) {
        if (!isNormalSaleCounter(destName) || destName == activeCounter) return
        val src = liveBill()
        val line = src.lines.value.firstOrNull { it.lineKey == lineKey } ?: return
        if (destName !in counters) counters.add(destName)
        val dest = counterBills.getOrPut(destName) { CounterBill(false) }
        val destLines = dest.lines.value.toMutableList()
        val existing = destLines.indexOfFirst { it.bindingId == line.bindingId && it.batchId == line.batchId }
        val moved = if (dest.billMode.value == "wholesale") {
            repriceWholesaleLine(
                line.copy(lineKey = System.nanoTime(), unitType = "pack"),
                dest.rateType.value,
                rateMargins,
            )
        } else {
            line.copy(lineKey = System.nanoTime())
        }
        if (existing >= 0) {
            destLines[existing] = destLines[existing].copy(qty = destLines[existing].qty + line.qty)
        } else {
            destLines.add(moved)
        }
        dest.lines.value = destLines
        src.lines.value = src.lines.value.filter { it.lineKey != lineKey }
        counterEpoch++
        error = "${line.productName} added to $destName."
    }

    fun appendLine(p: JsonObject, batch: JsonObject, allBatches: List<JsonObject>) {
        val target = liveBill()
        val cur = target.lines.value
        val bindingId = p.jInt("binding_id")
        val batchId = batch.jInt("batch_id")
        val existing = cur.indexOfFirst { it.bindingId == bindingId && it.batchId == batchId }
        if (existing >= 0) {
            val old = cur[existing]
            target.lines.value = cur.toMutableList().also {
                it[existing] = old.copy(qty = old.qty + 1)
            }
            counterEpoch++
        } else {
            val upp = batch.jInt("units_per_pack").takeIf { it > 0 }
                ?: p.jInt("units_per_pack").coerceAtLeast(1)
            val destMode = target.billMode.value
            val destRateType = target.rateType.value
            val unitMrp = batch.amount("unit_mrp").takeIf { it > 0 } ?: batch.amount("mrp")
            val stripMrp = if (destMode == "wholesale") {
                wholesaleStripMrpFromBatch(batch, upp)
            } else {
                batch.amount("pack_mrp").takeIf { it > 0 } ?: (unitMrp * upp)
            }
            val packPurchase = batch.amount("pack_purchase_rate").takeIf { it > 0 }
                ?: round2((batch.amount("purchase_rate").takeIf { it > 0 } ?: batch.amount("unit_purchase_rate")) * upp)
            val draft = SaleLineDraft(
                bindingId = bindingId,
                productName = p.str("product_name").ifBlank { p.str("name") },
                schedule = p.str("schedule_category").ifBlank { "OTC" },
                batchId = batchId,
                batchNo = batch.str("batch_no"),
                expiry = batch.str("expiry_date"),
                rate = if (destMode == "wholesale") 0.0 else unitMrp,
                gstRate = p.amount("gst_rate").takeIf { it > 0 } ?: p.amount("default_gst_rate"),
                hsn = p.str("hsn_code"),
                unitsPerPack = upp,
                qty = 1,
                unitType = if (destMode == "wholesale") "pack" else "unit",
                mfg = p.str("manufacturer_short").ifBlank { p.str("manufacturer") },
                shelf = p.str("shelf_location").ifBlank { batch.str("shelf_location") },
                pack = p.str("pack_size"),
                purchaseRate = batch.amount("purchase_rate").takeIf { it > 0 }
                    ?: batch.amount("unit_purchase_rate"),
                packMrp = stripMrp,
                unitMrp = unitMrp,
                unitNetCost = batch.amount("unit_net_cost").takeIf { it > 0 } ?: batch.amount("net_cost"),
                packPurchaseRate = packPurchase,
                ptr = batch.amount("ptr"),
                pts = batch.amount("pts"),
                category = p.str("product_category").ifBlank { "Allopathy" },
                schemePct = batch.amount("scheme_pct"),
                batches = allBatches,
            )
            target.lines.value = cur + if (destMode == "wholesale") {
                repriceWholesaleLine(draft, destRateType, rateMargins)
            } else {
                draft
            }
            counterEpoch++
        }
    }

    fun applyBatchToLine(lineKey: Long, batch: JsonObject) {
        updateLine(lineKey) { line ->
            val upp = batch.jInt("units_per_pack").takeIf { it > 0 } ?: line.unitsPerPack.coerceAtLeast(1)
            val unitMrp = batch.amount("unit_mrp").takeIf { it > 0 } ?: batch.amount("mrp")
            val stripMrp = if (billMode == "wholesale") {
                wholesaleStripMrpFromBatch(batch, upp)
            } else {
                batch.amount("pack_mrp").takeIf { it > 0 } ?: line.packMrp
            }
            val packPurchase = batch.amount("pack_purchase_rate").takeIf { it > 0 }
                ?: round2((batch.amount("purchase_rate").takeIf { it > 0 } ?: batch.amount("unit_purchase_rate")) * upp)
            val patched = line.copy(
                batchId = batch.jInt("batch_id"),
                batchNo = batch.str("batch_no"),
                expiry = batch.str("expiry_date"),
                unitsPerPack = upp,
                rate = if (billMode == "wholesale") line.rate else unitMrp,
                purchaseRate = batch.amount("purchase_rate").takeIf { it > 0 }
                    ?: batch.amount("unit_purchase_rate"),
                packMrp = stripMrp,
                unitMrp = unitMrp,
                unitNetCost = batch.amount("unit_net_cost").takeIf { it > 0 } ?: batch.amount("net_cost"),
                packPurchaseRate = packPurchase,
                ptr = batch.amount("ptr").takeIf { it > 0 } ?: line.ptr,
                pts = batch.amount("pts").takeIf { it > 0 } ?: line.pts,
                schemePct = batch.amount("scheme_pct"),
                shelf = batch.str("shelf_location").ifBlank { line.shelf },
            )
            if (billMode == "wholesale") repriceWholesaleLine(patched, rateType, rateMargins) else patched
        }
    }

    fun pickProduct(p: JsonObject) {
        val bindingId = p.jInt("binding_id")
        if (bindingId <= 0) return
        val sch = p.str("schedule_category").uppercase()
        if (isCashCounter && sch in CashCounterHiddenSchedules) {
            error = "${p.str("product_name").ifBlank { "This product" }} is Schedule $sch — not allowed on Cash Counter. Use Counter 1–3 or IPD."
            return
        }
        if (isCashCounter && isH1Schedule(sch)) {
            error = "Schedule H1 added here — pick Counter 1, 2 or 3 on that product row to send it."
        }
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.batches(bindingId) } }.onSuccess { batches ->
                when {
                    batches.isEmpty() -> error = "No batch in stock for this product."
                    batches.size == 1 -> {
                        appendLine(p, batches.first(), batches)
                        searchFocused = false
                    }
                    else -> batchPick = BatchPickState(p, batches)
                }
            }.onFailure { error = it.message }
        }
    }

    fun applyPatient(c: JsonObject) {
        customerId = c.jInt("id")
        customerName = c.str("name").uppercase()
        if (c.str("address").isNotBlank()) address = c.str("address").uppercase()
        if (c.str("email").isNotBlank()) email = c.str("email").trim().lowercase()
        patientPickExpanded = false
    }

    fun lookupPhone() {
        if (phone.length != 10) return
        val asked = phone
        lookingUp = true
        lookupNote = null
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.lookupCustomers(asked) } }
                    .onSuccess { list ->
                        if (phone != asked) return@onSuccess
                        val unique = list.distinctBy { c ->
                            val id = c.jInt("id")
                            if (id > 0) "id:$id" else "name:${c.str("name").trim().uppercase()}"
                        }.filter { it.str("name").isNotBlank() }
                        phoneMatches = unique
                        when (unique.size) {
                            0 -> {
                                customerId = 0
                                lookupNote = "New patient — enter name"
                            }
                            1 -> applyPatient(unique.first())
                            else -> {
                                customerId = 0
                                customerName = ""
                                patientPickExpanded = true
                                lookupNote = "${unique.size} patients on this number — select one"
                            }
                        }
                    }
                    .onFailure { lookupNote = it.message }
            } finally {
                lookingUp = false
            }
        }
    }

    fun openAgtSheet(orderId: Int, patientNo: String, contact: String) {
        agtSheetOpen = true
        agtSheetLoading = true
        scope.launch {
            try {
                val list = runCatching { withContext(Dispatchers.IO) { agtRepo.pendingByPatient(patientNo, contact) } }.getOrDefault(emptyList())
                var sel = list.firstOrNull { orderId > 0 && it.jInt("id") == orderId } ?: list.firstOrNull()
                var orders = list
                if (sel == null && orderId > 0) {
                    runCatching { withContext(Dispatchers.IO) { agtRepo.getOrder(orderId) } }.getOrNull()?.let {
                        orders = listOf(it)
                        sel = it
                    }
                }
                agtSheetOrders = orders
                agtSelected = sel
            } finally {
                agtSheetLoading = false
            }
        }
    }

    // AuraGharTak "Do Sale" handoff (web sessionStorage agt_do_sale): free counter + patient + open order.
    val agtHandoff by vm.agtDoSale.collectAsState()
    LaunchedEffect(agtHandoff) {
        val h = agtHandoff ?: return@LaunchedEffect
        vm.agtDoSale.value = null
        if (isEdit) return@LaunchedEffect
        val current = counterBills[activeCounter]
        if (current != null && current.inUse) {
            val free = counters.firstOrNull { c -> c != CASH_COUNTER && counterBills[c]?.inUse != true }
            if (free != null) switchCounter(free)
        }
        val target = counterBills.getOrPut(activeCounter) { CounterBill(activeCounter == CASH_COUNTER) }
        target.billMode.value = "retail"
        target.customerName.value = h.patientName.uppercase()
        target.phone.value = h.patientContact.filter { it.isDigit() }.takeLast(10)
        if (h.patientAddress.isNotBlank()) target.address.value = h.patientAddress.uppercase()
        h.patientNo.toIntOrNull()?.takeIf { it > 0 }?.let { target.customerId.intValue = it }
        target.customerExpanded.value = true
        counterEpoch++
        openAgtSheet(h.orderId, h.patientNo, h.patientContact)
    }

    // Pending AGT orders for this patient (retail only) → banner + sheet (web refreshAgtPendingForBill).
    LaunchedEffect(phone, customerId, billMode, activeCounter, canUseAgt) {
        val target = bill
        if (!canUseAgt || billMode != "retail" || (phone.length < 10 && customerId <= 0)) {
            target.agtPending.value = emptyList()
            return@LaunchedEffect
        }
        delay(500)
        runCatching {
            withContext(Dispatchers.IO) { agtRepo.pendingByPatient(if (customerId > 0) customerId.toString() else "", phone) }
        }.onSuccess { target.agtPending.value = it }
    }

    fun addAgtOrderToBill(order: JsonObject) {
        if (!agtOpenForSale(order)) {
            error = if (order.str("sale_invoice_no").isNotBlank()) {
                "Order ${order.str("order_no")} is already billed (${order.str("sale_invoice_no")})."
            } else {
                "Order ${order.str("order_no")} cannot be billed again (status: ${order.str("status")})."
            }
            return
        }
        val addable = agtObjects(order, "items").filter { agtObjects(it, "fulfillment_segments").isNotEmpty() }
        if (addable.isEmpty()) {
            error = "All items are out of stock for this order."
            return
        }
        val target = bill
        if (agtAdding) return
        agtAdding = true
        scope.launch {
            try {
                val added = mutableListOf<SaleLineDraft>()
                var skipped = 0
                for (item in addable) {
                    val productHit = runCatching { withContext(Dispatchers.IO) { repo.searchProducts(agtItemName(item)) } }.getOrDefault(emptyList())
                    for (seg in agtObjects(item, "fulfillment_segments")) {
                        val bindingId = seg.jInt("binding_id")
                        val batchId = seg.jInt("batch_id")
                        val qty = kotlin.math.round(seg.amount("qty")).toInt()
                        if (bindingId <= 0 || batchId <= 0 || qty <= 0) continue
                        val batches = runCatching { withContext(Dispatchers.IO) { repo.batches(bindingId) } }.getOrDefault(emptyList())
                        val batch = batches.firstOrNull { it.jInt("batch_id") == batchId }
                        if (batch == null) {
                            skipped++
                            continue
                        }
                        val p = productHit.firstOrNull { it.jInt("binding_id") == bindingId }
                        val unitMrp = batch.amount("unit_mrp").takeIf { it > 0 } ?: batch.amount("mrp")
                        added += SaleLineDraft(
                            bindingId = bindingId,
                            productName = p?.str("product_name")?.ifBlank { null } ?: agtItemName(item),
                            schedule = p?.str("schedule_category")?.ifBlank { null } ?: seg.str("p_cat").ifBlank { "OTC" },
                            batchId = batchId,
                            batchNo = batch.str("batch_no"),
                            expiry = batch.str("expiry_date"),
                            rate = unitMrp,
                            gstRate = p?.let { it.amount("gst_rate").takeIf { g -> g > 0 } ?: it.amount("default_gst_rate") } ?: 0.0,
                            hsn = p?.str("hsn_code").orEmpty(),
                            unitsPerPack = p?.jInt("units_per_pack")?.coerceAtLeast(1) ?: 1,
                            qty = qty,
                            mfg = p?.let { it.str("manufacturer_short").ifBlank { it.str("manufacturer") } }.orEmpty(),
                            shelf = p?.str("shelf_location").orEmpty(),
                            pack = p?.str("pack_size")?.ifBlank { null } ?: seg.str("pack_size"),
                            purchaseRate = batch.amount("purchase_rate").takeIf { it > 0 } ?: batch.amount("unit_purchase_rate"),
                            packMrp = batch.amount("mrp").takeIf { it > 0 } ?: seg.amount("pack_mrp"),
                            unitNetCost = batch.amount("unit_net_cost").takeIf { it > 0 } ?: batch.amount("net_cost"),
                            batches = batches,
                        )
                    }
                }
                if (added.isEmpty()) {
                    error = "Could not add any item — stock changed. Refresh the order and try again."
                } else {
                    var merged = target.lines.value
                    added.forEach { l ->
                        val i = merged.indexOfFirst { it.bindingId == l.bindingId && it.batchId == l.batchId }
                        merged = if (i >= 0) merged.toMutableList().also { it[i] = it[i].copy(qty = it[i].qty + l.qty) } else merged + l
                    }
                    target.lines.value = merged
                    target.agtOrderId.intValue = order.jInt("id")
                    target.agtOrderNo.value = order.str("order_no")
                    target.agtRxUrl.value = order.str("prescription_url")
                    target.customerName.value = order.str("patient_name").ifBlank { target.customerName.value }.uppercase()
                    order.str("patient_contact").filter { it.isDigit() }.takeLast(10).takeIf { it.length == 10 }?.let { target.phone.value = it }
                    if (order.str("patient_address").isNotBlank()) target.address.value = order.str("patient_address").uppercase()
                    agtSheetOpen = false
                    error = if (skipped > 0) "$skipped batch line(s) were skipped — stock changed since the order." else null
                }
            } finally {
                agtAdding = false
            }
        }
    }

    fun buildSaleBody(withWhatsapp: Boolean): JsonObject {
        val ws = billMode == "wholesale"
        val items = JsonArray()
        lines.forEach { line ->
            val upp = line.unitsPerPack.coerceAtLeast(1)
            // Wholesale qty is strips/packs → convert to base units for stock + API (web save).
            val baseQty = if (line.unitType == "pack") line.qty * upp else line.qty
            val freeBase = if (ws) {
                if (line.unitType == "pack") line.freeQty * upp else line.freeQty
            } else {
                0
            }
            val packRate = line.rate
            val baseRate = if (line.unitType == "pack") packRate / upp else packRate
            items.add(
                JsonObject().apply {
                    addProperty("product_binding_id", line.bindingId)
                    addProperty("schedule_category", line.schedule)
                    addProperty("quantity", baseQty)
                    addProperty("free_quantity", freeBase)
                    addProperty("rate", round2(baseRate))
                    addProperty("discount_pct", line.discountPct)
                    addProperty("batch_id", line.batchId)
                    addProperty("unit_type", line.unitType)
                    if (line.pack.isNotBlank()) addProperty("pack", line.pack)
                    if (line.shelf.isNotBlank()) addProperty("shelf", line.shelf)
                    if (line.hsn.isNotBlank()) addProperty("hsn_code", line.hsn)
                    if (ws) {
                        addProperty("oth_rate", line.othRate)
                        addProperty("base_rate", line.rate)
                        addProperty("net_rate", line.rate)
                        addProperty("billing_with", "Sale Rate")
                        addProperty("sale_scheme_pct", 0)
                    }
                },
            )
        }
        val patient = customerName.trim().ifBlank { "Walk-in" }
        return JsonObject().apply {
            if (!isEdit) addProperty("counter", activeCounter)
            addProperty("sale_type", saleType.replaceFirstChar { it.uppercase() })
            addProperty("bill_mode", billMode)
            if (ws) addProperty("rate_type", rateType)
            addProperty("invoice_date", billDate)
            addProperty("customer_name", patient)
            addProperty("patient_name", patient)
            if (customerId > 0) addProperty("customer_id", customerId)
            addProperty("contact_no", phone)
            if (email.isNotBlank()) addProperty("email", email)
            if (address.isNotBlank()) addProperty("address", address)
            addProperty("customer_type", when (billMode) {
                "wholesale" -> "wholesale"
                "ipd" -> "ipd"
                else -> "retail"
            })
            addProperty("doctor_name", doctor)
            addProperty("narration", narration)
            addProperty("payment_mode", paymentMode.lowercase())
            if (paymentMode == "Card" && accountId > 0) addProperty("account_id", accountId)
            if (paymentMode == PAY_CASH_UPI) addProperty("cash_amount", round2(cashAmount.toDoubleOrNull() ?: 0.0))
            if (billMode == "retail") addProperty("bill_discount_amount", billDiscount)
            if (ws) addProperty("cd_disc_pct", cdDiscPct)
            addProperty("send_whatsapp", if (withWhatsapp) 1 else 0)
            // Wholesale PTR/PTS = GST exclusive; retail = GST inclusive (web gstInclusive).
            addProperty("gst_inclusive", if (ws) 0 else 1)
            if (!isEdit && bill.agtOrderId.intValue > 0) addProperty("agt_order_id", bill.agtOrderId.intValue)
            add("items", items)
        }
    }

    fun saveBill(withWhatsapp: Boolean) {
        if (loading) return
        if (isEdit && !vm.hasFullAccess()) {
            error = "Only admins can edit saved bills. Use payment mode edit from the sales log."
            return
        }
        if (lines.isEmpty()) {
            error = "Add at least one product."
            return
        }
        if (isCashCounter && lines.any { isH1Schedule(it.schedule) }) {
            error = "Schedule H1 cannot stay on Cash Counter. Use the Counter dropdown on that product row (Counter 1, 2 or 3)."
            return
        }
        if (patientRequired && (customerName.isBlank() || customerName.trim().equals("walk-in", true))) {
            customerExpanded = true
            error = if (billMode == "wholesale") "Customer name is required for wholesale bill." else "Patient name is required."
            return
        }
        for (line in lines) {
            if (billMode == "wholesale" && line.rate <= 0) {
                error = "${line.productName}: enter a valid sale rate (PTR/PTS)."
                return
            }
            if (line.batchId <= 0) {
                error = "${line.productName}: no batch selected."
                return
            }
            val upp = line.unitsPerPack.coerceAtLeast(1)
            val needBase = if (line.unitType == "pack") {
                line.qty * upp + line.freeQty * upp
            } else {
                line.qty
            }
            val batch = line.batches.firstOrNull { it.jInt("batch_id") == line.batchId }
            val avail = batch?.let { batchStockQty(it) } ?: 0
            if (batch != null && needBase > avail) {
                val availLabel = if (billMode == "wholesale") {
                    "${avail / upp} strip(s) (${avail} units)"
                } else {
                    "$avail units"
                }
                error = "${line.productName}: batch ${line.batchNo} has only $availLabel available."
                return
            }
        }
        if (paymentMode == "Card" && accountId <= 0) {
            customerExpanded = true
            error = "Select bank account for Card payment."
            return
        }
        if (paymentMode == PAY_CASH_UPI) {
            val cash = cashAmount.toDoubleOrNull() ?: 0.0
            if (cash <= 0 || cash >= netTotal) {
                customerExpanded = true
                error = "Cash+UPI: enter the Cash amount — more than 0 and less than Net ${formatAmount2(netTotal)}."
                return
            }
        }
        if (withWhatsapp && phone.length != 10) {
            customerExpanded = true
            error = "Customer mobile number is required for Save & WhatsApp."
            return
        }
        loading = true
        val savingBill = bill
        val savingCounter = activeCounter
        val savingAgtNo = bill.agtOrderNo.value.takeIf { bill.agtOrderId.intValue > 0 }
        scope.launch {
            try {
                val body = buildSaleBody(withWhatsapp)
                runCatching {
                    withContext(Dispatchers.IO) {
                        if (isEdit && editId != null) repo.update(editId, body) else repo.create(body)
                    }
                }
                    .onSuccess { res ->
                        val inv = res.obj("data").str("invoice_no").ifBlank { res.str("invoice_no") }
                        if (isEdit) {
                            onSaved(inv)
                        } else {
                            // Stay on billing (web): clear only this counter; other counters keep their patients.
                            if (counterBills[savingCounter] === savingBill) {
                                counterBills[savingCounter] = CounterBill(savingCounter == CASH_COUNTER)
                                counterEpoch++
                            }
                            val net = res.obj("data").amount("net_amount").takeIf { it > 0 }
                            savedNote = "Bill ${inv.ifBlank { "" }} saved on ${savingCounter.lowercase().replaceFirstChar { it.uppercase() }}" +
                                (net?.let { " · Net ${formatAmount2(it)}" } ?: "") + "." +
                                (savingAgtNo?.let { " AuraGharTak order $it is now Ready for delivery." } ?: "")
                        }
                    }
                    .onFailure { error = it.message }
            } finally {
                loading = false
            }
        }
    }

    if (initLoading) {
        LoadingBlock("Loading bill…")
        return
    }

    batchPick?.let { pick ->
        BatchPickerSheet(
            productName = pick.product.str("product_name").ifBlank { pick.product.str("name") },
            batches = pick.batches,
            wholesale = isWholesale,
            onDismiss = { batchPick = null },
            onSelect = { batch ->
                appendLine(pick.product, batch, pick.batches)
                batchPick = null
                searchFocused = false
            },
        )
    }

    if (addCounterOpen) {
        var name by remember { mutableStateOf("COUNTER ${counters.size + 1}") }
        AlertDialog(
            onDismissRequest = { addCounterOpen = false },
            title = { Text("New counter", style = MaterialTheme.typography.titleSmall) },
            text = {
                SalesFormField(
                    label = "Counter name",
                    value = name,
                    onValueChange = { name = it.uppercase().take(24) },
                    modifier = Modifier.fillMaxWidth(),
                )
            },
            confirmButton = {
                TextButton(
                    enabled = name.isNotBlank(),
                    onClick = {
                        val n = name.trim()
                        if (n !in counters) counters.add(n)
                        addCounterOpen = false
                        switchCounter(n)
                    },
                ) { Text("Add", fontWeight = FontWeight.SemiBold) }
            },
            dismissButton = { TextButton(onClick = { addCounterOpen = false }) { Text("Cancel") } },
        )
    }

    lineBatchPick?.let { line ->
        BatchPickerSheet(
            productName = line.productName,
            batches = line.batches,
            wholesale = isWholesale,
            onDismiss = { lineBatchPick = null },
            onSelect = { batch ->
                applyBatchToLine(line.lineKey, batch)
                lineBatchPick = null
            },
        )
    }

    if (agtSheetOpen) {
        AgtBillOrdersSheet(
            orders = agtSheetOrders,
            selected = agtSelected,
            repo = agtRepo,
            loading = agtSheetLoading,
            adding = agtAdding,
            onSelect = { agtSelected = it },
            onAdd = { o ->
                val n = agtObjects(o, "items").count { agtObjects(it, "fulfillment_segments").isNotEmpty() }
                if (n == 0) error = "All items are out of stock for this order." else agtConfirm = o
            },
            onRx = { o -> agtRx = o.str("prescription_url") to "Prescription · ${o.str("order_no")}" },
            onDismiss = { agtSheetOpen = false },
        )
    }
    agtConfirm?.let { o ->
        val n = agtObjects(o, "items").count { agtObjects(it, "fulfillment_segments").isNotEmpty() }
        AlertDialog(
            onDismissRequest = { agtConfirm = null },
            title = { Text("Add to this bill?", style = MaterialTheme.typography.titleSmall) },
            text = { Text("Add AuraGharTak order ${o.str("order_no")} to this bill? ($n item(s))", style = MaterialTheme.typography.bodySmall) },
            confirmButton = {
                TextButton(onClick = {
                    agtConfirm = null
                    addAgtOrderToBill(o)
                }) { Text("Add to bill", fontWeight = FontWeight.SemiBold) }
            },
            dismissButton = { TextButton(onClick = { agtConfirm = null }) { Text("Cancel") } },
        )
    }
    agtRx?.let { (url, title) -> AgtRxViewer(url, title, agtRepo) { agtRx = null } }

    val imeVisible = WindowInsets.isImeVisible
    Scaffold(
        contentWindowInsets = WindowInsets(0, 0, 0, 0),
        topBar = {
            CompactFormTopBar(
                title = when {
                    isEdit -> "Edit bill"
                    billMode == "wholesale" -> "Wholesale sale"
                    billMode == "ipd" -> "IPD sale"
                    else -> "Retail sale"
                },
                onClose = onBack,
            )
        },
        bottomBar = {
            // Hide save bar while typing so the keyboard does not cover fields / actions.
            if (!searchFocused && !imeVisible) {
            Surface(shadowElevation = 8.dp, tonalElevation = 2.dp) {
                Column(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 6.dp)) {
                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(
                                if (isEdit) "Items ${lines.size}" else "${activeCounter.lowercase().replaceFirstChar { it.uppercase() }} · Items ${lines.size}",
                                style = MaterialTheme.typography.labelSmall,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                            )
                            Text(
                                "Net ${formatInr(netTotal)}",
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.Bold,
                                color = PharmaTeal,
                            )
                            if (lines.isNotEmpty()) {
                                Text(
                                    buildString {
                                        if (isWholesale) {
                                            append("GST ${formatInr(gstEstimate)}")
                                            if (cdDiscPct > 0) append(" · CD ${formatAmount2(cdDiscPct)}%")
                                        } else {
                                            append("GST ≈ ${formatInr(gstEstimate)}")
                                        }
                                        if (roundOff != 0.0) append(" · Round off ${if (roundOff > 0) "+" else ""}${formatAmount2(roundOff)}")
                                    },
                                    style = MaterialTheme.typography.labelSmall,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis,
                                )
                            }
                        }
                        if (paymentMode == PAY_CASH_UPI && lines.isNotEmpty()) {
                            val cash = cashAmount.toDoubleOrNull() ?: 0.0
                            Column(horizontalAlignment = Alignment.End) {
                                Text("Cash ${formatAmount2(cash)}", style = MaterialTheme.typography.labelSmall, color = PharmaSlate)
                                Text("UPI ${formatAmount2((netTotal - cash).coerceAtLeast(0.0))}", style = MaterialTheme.typography.labelSmall, color = PharmaSlate)
                            }
                        }
                    }
                    Spacer(Modifier.height(6.dp))
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PharmaPrimaryButton(
                            text = if (loading) "Saving…" else "Save",
                            modifier = Modifier.weight(1f),
                            enabled = !loading && lines.isNotEmpty(),
                        ) { saveBill(withWhatsapp = false) }
                        PharmaPrimaryButton(
                            text = "Save & WhatsApp",
                            modifier = Modifier.weight(1f),
                            enabled = !loading && lines.isNotEmpty(),
                        ) { saveBill(withWhatsapp = true) }
                    }
                }
            }
            }
        },
    ) { padding ->
        val scrollState = rememberScrollState()
        val catalogMaxHeight = (LocalConfiguration.current.screenHeightDp * 0.22f).coerceIn(120f, 200f).dp
        val linesMaxHeight = (LocalConfiguration.current.screenHeightDp * 0.28f).coerceIn(140f, 260f).dp
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .imePadding()
                .verticalScroll(scrollState)
                .padding(horizontal = CompactScreenPadding)
                .padding(top = CompactFormHeaderGap)
                .navigationBarsPadding(),
        ) {
            if (!isEdit) {
                CounterTabs(
                    counters = counters,
                    active = activeCounter,
                    billFor = { counterBills[it] },
                    epoch = counterEpoch,
                    onSelect = { switchCounter(it) },
                    onAdd = { addCounterOpen = true },
                    onRemove = { c ->
                        if (counters.size > 1) {
                            counters.remove(c)
                            counterBills.remove(c)
                            if (activeCounter == c) switchCounter(counters.first()) else counterEpoch++
                        }
                    },
                )
                Spacer(Modifier.height(CompactFormHeaderGap))
            }
            // Prominent Retail / Wholesale switch (was buried in Add products — easy to miss).
            if (showBillModeSwitch) {
                RetailWholesaleModeBar(
                    billMode = billMode,
                    onSelect = { applyBillMode(it) },
                )
                Spacer(Modifier.height(6.dp))
            } else if (isCashCounter) {
                Text(
                    "Cash Counter · Retail only · H1: send to Counter 1–3 from the product row",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    modifier = Modifier.padding(bottom = 4.dp),
                )
            }
            if (isWholesale) {
                WholesaleRateBar(
                    rateType = rateType,
                    onRateType = { applyRateType(it) },
                    cdPct = cdDiscPct,
                    onCdPct = { cdDiscPct = it.coerceAtLeast(0.0) },
                )
                Spacer(Modifier.height(6.dp))
            }
            savedNote?.let { note ->
                Surface(color = Color(0xFFDCFCE7), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(horizontal = 8.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.CheckCircle, null, Modifier.size(16.dp), tint = Color(0xFF15803D))
                        Text(note, Modifier.weight(1f).padding(start = 6.dp), style = MaterialTheme.typography.labelSmall, color = Color(0xFF166534))
                        TextButton(onClick = { onSaved("") }, contentPadding = PaddingValues(horizontal = 6.dp)) {
                            Text("Sales log", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
                        }
                        IconButton(onClick = { savedNote = null }, modifier = Modifier.size(24.dp)) {
                            Icon(Icons.Default.Close, "Dismiss", Modifier.size(14.dp))
                        }
                    }
                }
                Spacer(Modifier.height(4.dp))
            }
            if (error != null) {
                ErrorBanner(error!!) { error = null }
                Spacer(Modifier.height(4.dp))
            }
            AddProductSearchCard(
                billMode = billMode,
                wholesale = isWholesale,
                saleType = saleType,
                onSaleType = { saleType = it },
                cashCounter = isCashCounter,
                addProductExpanded = addProductExpanded,
                onToggleExpand = {
                    addProductExpanded = !addProductExpanded
                    if (!addProductExpanded) searchFocused = false
                },
                catalogListExpanded = catalogListExpanded,
                onCatalogListExpanded = { catalogListExpanded = it },
                hits = hits,
                onPickProduct = { pickProduct(it) },
                catalogMaxHeight = catalogMaxHeight,
                searchMode = searchMode,
                searchModeExpanded = searchModeExpanded,
                onSearchModeExpanded = { searchModeExpanded = it },
                onSearchMode = { mode ->
                    searchMode = mode
                    searchModeExpanded = false
                    hits = emptyList()
                    query = ""
                },
                genericExpanded = genericExpanded,
                onGenericExpanded = { genericExpanded = it },
                genericLabel = genericLabel,
                generics = generics,
                onGenericPick = { g ->
                    genericId = g.jInt("id")
                    genericLabel = g.str("name")
                    genericExpanded = false
                },
                generalStockOnly = generalStockOnly,
                onGeneralStockOnly = { generalStockOnly = it },
                query = query,
                onQuery = { query = it },
                onSearchFocus = { searchFocused = it },
                searchLoading = searchLoading,
                hitCount = hits.size,
            )

            if (canUseAgt && bill.agtOrderId.intValue > 0) {
                Spacer(Modifier.height(6.dp))
                Surface(color = Color(0xFFF0FDFA), shape = RoundedCornerShape(8.dp), border = BorderStroke(1.dp, PharmaTeal.copy(alpha = 0.35f)), modifier = Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(start = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.LocalShipping, null, Modifier.size(16.dp), tint = PharmaTeal)
                        Text(
                            "AuraGharTak ${bill.agtOrderNo.value} linked — saving marks it Ready",
                            Modifier.weight(1f).padding(start = 6.dp),
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaSlate,
                            maxLines = 2,
                        )
                        if (bill.agtRxUrl.value.isNotBlank()) {
                            TextButton(
                                onClick = { agtRx = bill.agtRxUrl.value to "Prescription · ${bill.agtOrderNo.value}" },
                                contentPadding = PaddingValues(horizontal = 6.dp),
                            ) { Text("View Rx", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold) }
                        }
                        IconButton(onClick = {
                            bill.agtOrderId.intValue = 0
                            bill.agtOrderNo.value = ""
                            bill.agtRxUrl.value = ""
                        }, modifier = Modifier.size(28.dp)) {
                            Icon(Icons.Default.Close, "Unlink order", Modifier.size(14.dp), tint = PharmaMuted)
                        }
                    }
                }
            } else if (canUseAgt && billMode == "retail" && agtPending.isNotEmpty()) {
                Spacer(Modifier.height(6.dp))
                Surface(color = Color(0xFFFEF3C7), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(start = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.LocalShipping, null, Modifier.size(16.dp), tint = Color(0xFF92400E))
                        Text(
                            "${agtPending.size} pending AuraGharTak order(s) for this patient",
                            Modifier.weight(1f).padding(start = 6.dp),
                            style = MaterialTheme.typography.labelSmall,
                            color = Color(0xFF92400E),
                        )
                        TextButton(onClick = {
                            agtSheetOrders = agtPending
                            agtSelected = agtPending.first()
                            agtSheetLoading = false
                            agtSheetOpen = true
                        }, contentPadding = PaddingValues(horizontal = 8.dp)) {
                            Text("Open", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
            }
            Spacer(Modifier.height(6.dp))
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "Added products",
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = PharmaSlate,
                    modifier = Modifier.weight(1f),
                )
                Text(
                    "${lines.size} item(s)",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaTeal,
                )
            }
            Spacer(Modifier.height(2.dp))
            if (lines.isEmpty()) {
                Text(
                    "No products yet. Search and tap a product above.",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    modifier = Modifier.padding(vertical = 4.dp),
                )
            } else if (billMode == "wholesale") {
                BillLinesWholesaleGrid(
                    lines = lines,
                    cdPct = cdDiscPct,
                    maxBodyHeight = linesMaxHeight,
                    onQty = { key, v -> updateLine(key) { syncDiscFromPct(it.copy(qty = v.coerceAtLeast(1)), it.discountPct) } },
                    onFree = { key, v -> updateLine(key) { it.copy(freeQty = v.coerceAtLeast(0)) } },
                    onDisc = { key, v -> updateLine(key) { syncDiscFromPct(it, v) } },
                    onRate = { key, v -> updateLine(key) { syncDiscFromPct(it.copy(rate = v.coerceAtLeast(0.0)), it.discountPct) } },
                    onBatch = { lineBatchPick = it },
                    onRemove = { removeLine(it) },
                )
            } else {
                BillLinesRetailGrid(
                    lines = lines,
                    maxBodyHeight = linesMaxHeight,
                    showCounterPicker = isCashCounter,
                    counterOptions = normalCounters(),
                    onMoveToCounter = { key, dest -> moveLineToCounter(key, dest) },
                    onQty = { key, v ->
                        updateLine(key) { syncDiscFromPct(it.copy(qty = v.coerceAtLeast(1)), it.discountPct) }
                    },
                    onDiscPct = { key, v -> updateLine(key) { syncDiscFromPct(it, v) } },
                    onDiscAmt = { key, v -> updateLine(key) { syncDiscFromAmt(it, v) } },
                    onBatch = { lineBatchPick = it },
                    onRemove = { removeLine(it) },
                )
            }

            Spacer(Modifier.height(6.dp))

            Column(
                Modifier
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(8.dp))
                    .background(Color.White)
                    .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
                    .padding(6.dp),
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Row(
                    Modifier.fillMaxWidth().clickable { customerExpanded = !customerExpanded },
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        "Customer & bill details",
                        style = MaterialTheme.typography.labelSmall,
                        fontWeight = FontWeight.SemiBold,
                        color = PharmaSlate,
                        modifier = Modifier.weight(1f),
                    )
                    if (!customerExpanded && customerName.isNotBlank()) {
                        Text(
                            customerName,
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaTeal,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.widthIn(max = 140.dp).padding(end = 4.dp),
                        )
                    }
                    Icon(
                        if (customerExpanded) Icons.Default.ExpandLess else Icons.Default.ExpandMore,
                        contentDescription = if (customerExpanded) "Collapse" else "Expand",
                        modifier = Modifier.size(20.dp),
                        tint = PharmaSlate,
                    )
                }
                if (customerExpanded) {
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        SalesFormField(
                            label = "Bill date",
                            value = billDate,
                            onValueChange = { billDate = it },
                            placeholder = "yyyy-MM-dd",
                            modifier = Modifier.weight(1f),
                        )
                        SalesFormField(
                            label = "Mobile",
                            value = phone,
                            onValueChange = { v ->
                                val digits = v.filter { ch -> ch.isDigit() }.take(10)
                                val changed = digits != phone
                                phone = digits
                                if (changed) {
                                    phoneMatches = emptyList()
                                    lookupNote = null
                                    if (digits.length == 10) lookupPhone()
                                }
                            },
                            placeholder = "10 digits",
                            keyboardType = KeyboardType.Phone,
                            modifier = Modifier.weight(1f),
                            trailingIcon = {
                                if (lookingUp) {
                                    CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                                } else {
                                    IconButton(onClick = { lookupPhone() }, modifier = Modifier.size(24.dp)) {
                                        Icon(
                                            Icons.Default.Search,
                                            contentDescription = "Lookup patient",
                                            modifier = Modifier.size(16.dp),
                                            tint = PharmaTeal,
                                        )
                                    }
                                }
                            },
                        )
                    }
                    lookupNote?.let {
                        Text(
                            it,
                            style = MaterialTheme.typography.labelSmall,
                            color = if (phoneMatches.size > 1) Color(0xFFB45309) else PharmaMuted,
                        )
                    }
                    if (phoneMatches.size > 1) {
                        val selected = phoneMatches.firstOrNull {
                            customerId > 0 && it.jInt("id") == customerId
                        } ?: phoneMatches.firstOrNull {
                            customerName.isNotBlank() && it.str("name").equals(customerName, ignoreCase = true)
                        }
                        CompactSelectDropdown(
                            label = "Patients on this number (${phoneMatches.size})",
                            value = selected?.str("name")?.uppercase() ?: "Select patient…",
                            expanded = patientPickExpanded,
                            onExpandedChange = { patientPickExpanded = it },
                            modifier = Modifier.fillMaxWidth(),
                            dense = true,
                        ) {
                            ExposedDropdownMenu(
                                expanded = patientPickExpanded,
                                onDismissRequest = { patientPickExpanded = false },
                            ) {
                                phoneMatches.forEach { c ->
                                    DropdownMenuItem(
                                        text = {
                                            Column {
                                                Text(
                                                    c.str("name").uppercase(),
                                                    style = MaterialTheme.typography.bodySmall,
                                                    color = PharmaSlate,
                                                )
                                                val sub = c.str("address").ifBlank { c.str("email") }
                                                if (sub.isNotBlank()) {
                                                    Text(
                                                        sub,
                                                        style = MaterialTheme.typography.labelSmall,
                                                        maxLines = 1,
                                                        overflow = TextOverflow.Ellipsis,
                                                    )
                                                }
                                            }
                                        },
                                        onClick = {
                                            applyPatient(c)
                                            lookupNote = null
                                        },
                                    )
                                }
                                DropdownMenuItem(
                                    text = {
                                        Text(
                                            "+ New patient on this number",
                                            style = MaterialTheme.typography.bodySmall,
                                            color = PharmaTeal,
                                        )
                                    },
                                    onClick = {
                                        customerId = 0
                                        customerName = ""
                                        patientPickExpanded = false
                                        lookupNote = "New patient — enter name"
                                    },
                                )
                            }
                        }
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        SalesFormField(
                            label = when {
                                billMode == "wholesale" -> "Customer name *"
                                patientRequired -> "Patient name *"
                                else -> "Patient name (Walk-in)"
                            },
                            value = customerName,
                            onValueChange = {
                                customerName = it.uppercase()
                                if (customerId > 0 && phoneMatches.none { m -> m.str("name").equals(it, true) }) {
                                    customerId = 0
                                }
                            },
                            isError = patientRequired && error?.contains("name is required") == true && customerName.isBlank(),
                            modifier = Modifier.weight(1f),
                        )
                        SalesFormField(
                            label = "Doctor",
                            value = doctor,
                            onValueChange = { doctor = it.uppercase() },
                            modifier = Modifier.weight(1f),
                        )
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        SalesFormField(
                            label = "Email",
                            value = email,
                            onValueChange = { email = it.trim() },
                            keyboardType = KeyboardType.Email,
                            modifier = Modifier.weight(1f),
                        )
                        if (billMode == "retail") {
                            SalesFormField(
                                label = "Bulk discount ₹",
                                value = billDiscAmt,
                                onValueChange = { billDiscAmt = it },
                                keyboardType = KeyboardType.Decimal,
                                modifier = Modifier.weight(1f),
                            )
                        }
                    }
                    SalesFormField(
                        label = "Address",
                        value = address,
                        onValueChange = { address = it.uppercase() },
                        modifier = Modifier.fillMaxWidth(),
                    )
                    SalesFormField(
                        label = "Bill narration",
                        value = narration,
                        onValueChange = { narration = it },
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Text("Payment mode", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    PayModeChips(payModes, paymentMode) { m ->
                        paymentMode = m
                        if (m != "Card") accountId = 0
                        if (m != PAY_CASH_UPI) cashAmount = ""
                    }
                    if (paymentMode == PAY_CASH_UPI) {
                        CashUpiSplit(net = netTotal, cashAmount = cashAmount, onCashAmount = { cashAmount = it })
                    }
                    if (lines.isNotEmpty()) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            Text("Before round ${formatAmount2(netBeforeRound)}", Modifier.weight(1f), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            Text(
                                "Round off ${if (roundOff > 0) "+" else ""}${formatAmount2(roundOff)}",
                                style = MaterialTheme.typography.labelSmall,
                                color = PharmaMuted,
                            )
                            Text("Net ${formatAmount2(netTotal)}", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = PharmaTeal)
                        }
                    }
                    if (paymentMode == "Card") {
                        val acc = accounts.firstOrNull { it.jInt("id") == accountId }
                        CompactSelectDropdown(
                            label = "Bank account *",
                            value = acc?.str("name") ?: "Select account…",
                            expanded = accountExpanded,
                            onExpandedChange = { accountExpanded = it },
                            modifier = Modifier.fillMaxWidth(),
                            dense = true,
                        ) {
                            ExposedDropdownMenu(
                                expanded = accountExpanded,
                                onDismissRequest = { accountExpanded = false },
                            ) {
                                accounts.forEach { a ->
                                    DropdownMenuItem(
                                        text = { Text(a.str("name"), style = MaterialTheme.typography.bodySmall) },
                                        onClick = {
                                            accountId = a.jInt("id")
                                            accountExpanded = false
                                        },
                                    )
                                }
                            }
                        }
                    }
                }
            }

            // Extra space so the last fields can scroll fully above the keyboard.
            Spacer(Modifier.height(if (imeVisible) 96.dp else 16.dp))
        }
    }
}

private data class BatchPickState(val product: JsonObject, val batches: List<JsonObject>)

/** Counter tabs (web billing): caption = patient once entered, else counter name; badge = item count. */
@Composable
private fun CounterTabs(
    counters: List<String>,
    active: String,
    billFor: (String) -> CounterBill?,
    @Suppress("UNUSED_PARAMETER") epoch: Int,
    onSelect: (String) -> Unit,
    onAdd: () -> Unit,
    onRemove: (String) -> Unit,
) {
    var confirmRemove by remember { mutableStateOf<String?>(null) }
    LazyRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
        items(counters, key = { it }) { c ->
            val b = billFor(c)
            val sel = c == active
            val items = b?.lines?.value?.size ?: 0
            val inUse = b?.inUse == true
            Row(
                Modifier
                    .height(30.dp)
                    .clip(RoundedCornerShape(6.dp))
                    .background(if (sel) PharmaTeal else if (inUse) Color(0xFFE6F4F1) else Color.White)
                    .border(1.dp, if (sel) PharmaTeal else if (inUse) PharmaTeal.copy(alpha = 0.5f) else Color(0xFFCBD5E1), RoundedCornerShape(6.dp))
                    .clickable { onSelect(c) }
                    .padding(start = 8.dp, end = if (sel && counters.size > 1) 2.dp else 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    b?.label ?: c,
                    style = MaterialTheme.typography.labelSmall,
                    fontWeight = if (sel) FontWeight.SemiBold else FontWeight.Normal,
                    color = if (sel) Color.White else PharmaSlate,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.widthIn(max = 120.dp),
                )
                if (items > 0) {
                    Box(
                        Modifier
                            .padding(start = 4.dp)
                            .clip(RoundedCornerShape(8.dp))
                            .background(if (sel) Color.White else PharmaTeal)
                            .padding(horizontal = 5.dp, vertical = 1.dp),
                    ) {
                        Text("$items", style = MaterialTheme.typography.labelSmall, color = if (sel) PharmaTeal else Color.White, fontWeight = FontWeight.Bold)
                    }
                }
                if (sel && counters.size > 1) {
                    IconButton(
                        onClick = { if (inUse) confirmRemove = c else onRemove(c) },
                        modifier = Modifier.size(22.dp),
                    ) {
                        Icon(Icons.Default.Close, "Remove counter", Modifier.size(12.dp), tint = Color.White)
                    }
                }
            }
        }
        item {
            Box(
                Modifier
                    .size(30.dp)
                    .clip(RoundedCornerShape(6.dp))
                    .border(1.dp, PharmaTeal, RoundedCornerShape(6.dp))
                    .clickable(onClick = onAdd),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Default.Add, "Add counter", Modifier.size(16.dp), tint = PharmaTeal)
            }
        }
    }
    confirmRemove?.let { c ->
        AlertDialog(
            onDismissRequest = { confirmRemove = null },
            title = { Text("Remove counter?", style = MaterialTheme.typography.titleSmall) },
            text = { Text("Counter \"$c\" has a patient or items. Remove and discard this bill?", style = MaterialTheme.typography.bodySmall) },
            confirmButton = {
                TextButton(onClick = { onRemove(c); confirmRemove = null }) {
                    Text("Remove", color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold)
                }
            },
            dismissButton = { TextButton(onClick = { confirmRemove = null }) { Text("Keep") } },
        )
    }
}

/** Web-style Retail | Wholesale mode switch under the counter tabs. */
@Composable
private fun RetailWholesaleModeBar(
    billMode: String,
    onSelect: (String) -> Unit,
) {
    val wholesale = billMode == "wholesale"
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(if (wholesale) Color(0xFFECFDF5) else Color(0xFFF1F5F9))
            .border(1.dp, if (wholesale) PharmaTeal.copy(alpha = 0.45f) else PharmaBorder, RoundedCornerShape(8.dp))
            .padding(4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            if (wholesale) "Wholesale / PCD bill" else "Retail sales bill",
            style = MaterialTheme.typography.labelSmall,
            fontWeight = FontWeight.SemiBold,
            color = PharmaSlate,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier
                .weight(1f)
                .padding(start = 6.dp, end = 4.dp),
        )
        Row(
            Modifier
                .clip(RoundedCornerShape(7.dp))
                .background(Color.White)
                .border(1.dp, PharmaBorder, RoundedCornerShape(7.dp))
                .padding(2.dp),
        ) {
            ModeSwitchOpt(
                label = "Retail",
                selected = !wholesale && billMode != "ipd",
                onClick = { onSelect("retail") },
            )
            ModeSwitchOpt(
                label = "Wholesale",
                selected = wholesale,
                onClick = { onSelect("wholesale") },
            )
        }
    }
}

@Composable
private fun ModeSwitchOpt(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
) {
    Box(
        Modifier
            .clip(RoundedCornerShape(6.dp))
            .background(if (selected) PharmaTeal else Color.Transparent)
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 7.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            label,
            style = MaterialTheme.typography.labelSmall,
            fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium,
            color = if (selected) Color.White else PharmaSlate,
        )
    }
}

/** PTR / PTS rate type + CD% (web wholesale toolbar). */
@Composable
private fun WholesaleRateBar(
    rateType: String,
    onRateType: (String) -> Unit,
    cdPct: Double,
    onCdPct: (Double) -> Unit,
) {
    var cdText by remember(cdPct) { mutableStateOf(if (cdPct == 0.0) "0" else cdPct.toString()) }
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(Color.White)
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .padding(horizontal = 8.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Text("Rate", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        Row(
            Modifier
                .clip(RoundedCornerShape(6.dp))
                .border(1.dp, PharmaBorder, RoundedCornerShape(6.dp))
                .padding(2.dp),
        ) {
            ModeSwitchOpt("PTR", rateType != "pts") { onRateType("ptr") }
            ModeSwitchOpt("PTS", rateType == "pts") { onRateType("pts") }
        }
        Text("CD%", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        SalesDenseSearchField(
            value = cdText,
            onValueChange = { v ->
                if (v.isEmpty() || Regex("""^\d{0,3}(\.\d{0,2})?$""").matches(v)) {
                    cdText = v
                    onCdPct(v.toDoubleOrNull() ?: 0.0)
                }
            },
            modifier = Modifier.width(72.dp),
            placeholder = "0",
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
        )
        Text(
            "Qty = strips",
            style = MaterialTheme.typography.labelSmall,
            color = PharmaTeal,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

private object BillRetailCols {
    val product = 132.dp
    val mfg = 36.dp
    val qty = 52.dp
    val discPct = 52.dp
    val batch = 76.dp
    val shelf = 36.dp
    val purRate = 56.dp
    val exp = 52.dp
    val sch = 44.dp
    val pack = 48.dp
    val mrp = 56.dp
    val taxable = 64.dp
    val discAmt = 56.dp
    val profit = 56.dp
    val gst = 44.dp
    val net = 64.dp
    val counter = 88.dp
    val action = 36.dp
    val gap = 4.dp
}

internal val BillGridHeaderBg = Color(0xFFF1F5F9)

@Composable
internal fun BillGridTh(text: String, width: Dp, alignEnd: Boolean = false) {
    Text(
        text,
        Modifier.width(width).padding(horizontal = 2.dp, vertical = 6.dp),
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.SemiBold,
        color = Color(0xFF64748B),
        textAlign = if (alignEnd) TextAlign.End else TextAlign.Start,
        maxLines = 1,
        overflow = TextOverflow.Ellipsis,
    )
}

@Composable
internal fun BillGridCellText(
    text: String,
    width: Dp,
    alignEnd: Boolean = false,
    color: Color = Color.Unspecified,
    fontWeight: FontWeight = FontWeight.Normal,
) {
    Text(
        text,
        Modifier.width(width).padding(horizontal = 2.dp, vertical = 4.dp),
        style = MaterialTheme.typography.labelSmall,
        textAlign = if (alignEnd) TextAlign.End else TextAlign.Start,
        maxLines = 2,
        overflow = TextOverflow.Ellipsis,
        color = color,
        fontWeight = fontWeight,
    )
}

@Composable
private fun BillGridNumInput(
    value: String,
    onValueChange: (String) -> Unit,
    width: Dp,
) {
    BasicTextField(
        value = value,
        onValueChange = onValueChange,
        modifier = Modifier
            .width(width)
            .height(36.dp)
            .border(1.dp, PharmaBorder, RoundedCornerShape(6.dp))
            .background(Color.White, RoundedCornerShape(6.dp))
            .padding(horizontal = 6.dp, vertical = 8.dp),
        textStyle = MaterialTheme.typography.labelSmall.copy(
            textAlign = TextAlign.End,
            color = PharmaSlate,
        ),
        singleLine = true,
        decorationBox = { inner ->
            Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.CenterEnd) {
                inner()
            }
        },
    )
}

@Composable
private fun BillLinesRetailGrid(
    lines: List<SaleLineDraft>,
    maxBodyHeight: Dp,
    showCounterPicker: Boolean = false,
    counterOptions: List<String> = emptyList(),
    onMoveToCounter: (Long, String) -> Unit = { _, _ -> },
    onQty: (Long, Int) -> Unit,
    onDiscPct: (Long, Double) -> Unit,
    onDiscAmt: (Long, Double) -> Unit,
    onBatch: (SaleLineDraft) -> Unit,
    onRemove: (Long) -> Unit,
) {
    val scroll = rememberScrollState()
    val bodyScroll = rememberScrollState()
    LaunchedEffect(lines.size) { bodyScroll.animateScrollTo(bodyScroll.maxValue) }
    BillLinesFrame {
        Row(
            Modifier
                .fillMaxWidth()
                .horizontalScroll(scroll)
                .background(BillGridHeaderBg),
            horizontalArrangement = Arrangement.spacedBy(BillRetailCols.gap),
        ) {
            BillGridTh("PRODUCT", BillRetailCols.product)
            if (showCounterPicker) BillGridTh("COUNTER", BillRetailCols.counter)
            BillGridTh("MFG", BillRetailCols.mfg)
            BillGridTh("QTY", BillRetailCols.qty, alignEnd = true)
            BillGridTh("DISC%", BillRetailCols.discPct, alignEnd = true)
            BillGridTh("BATCH", BillRetailCols.batch)
            BillGridTh("SH", BillRetailCols.shelf)
            BillGridTh("RATE", BillRetailCols.purRate, alignEnd = true)
            BillGridTh("EXP", BillRetailCols.exp)
            BillGridTh("SCH", BillRetailCols.sch)
            BillGridTh("PACK", BillRetailCols.pack)
            BillGridTh("MRP", BillRetailCols.mrp, alignEnd = true)
            BillGridTh("TAXABLE", BillRetailCols.taxable, alignEnd = true)
            BillGridTh("DISC₹", BillRetailCols.discAmt, alignEnd = true)
            BillGridTh("PROFIT", BillRetailCols.profit, alignEnd = true)
            BillGridTh("GST", BillRetailCols.gst, alignEnd = true)
            BillGridTh("NET", BillRetailCols.net, alignEnd = true)
            BillGridTh("", BillRetailCols.action)
        }
        HorizontalDivider(color = PharmaBorder)
        Column(Modifier.heightIn(max = maxBodyHeight).verticalScroll(bodyScroll)) {
            lines.forEachIndexed { index, line ->
                BillLineRetailRow(
                    line = line,
                    scroll = scroll,
                    showCounterPicker = showCounterPicker,
                    counterOptions = counterOptions,
                    onMoveToCounter = { onMoveToCounter(line.lineKey, it) },
                    onQty = { onQty(line.lineKey, it) },
                    onDiscPct = { onDiscPct(line.lineKey, it) },
                    onDiscAmt = { onDiscAmt(line.lineKey, it) },
                    onBatch = { onBatch(line) },
                    onRemove = { onRemove(line.lineKey) },
                )
                if (index < lines.lastIndex) {
                    HorizontalDivider(color = PharmaBorder.copy(alpha = 0.5f))
                }
            }
        }
    }
}

/** Compact Counter 1 / 2 / 3 picker on a Cash Counter product row. */
@Composable
private fun LineCounterPicker(
    options: List<String>,
    onSelect: (String) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    Box(Modifier.width(BillRetailCols.counter)) {
        Row(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(6.dp))
                .background(Color(0xFFFEF3C7))
                .border(1.dp, Color(0xFFF59E0B), RoundedCornerShape(6.dp))
                .clickable(enabled = options.isNotEmpty()) { expanded = true }
                .padding(horizontal = 6.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                "Send to…",
                style = MaterialTheme.typography.labelSmall,
                fontWeight = FontWeight.SemiBold,
                color = Color(0xFF92400E),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f),
            )
        }
        DropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false },
        ) {
            options.forEach { c ->
                DropdownMenuItem(
                    text = {
                        Text(c, style = MaterialTheme.typography.labelSmall, color = PharmaSlate)
                    },
                    onClick = {
                        expanded = false
                        onSelect(c)
                    },
                )
            }
        }
    }
}

/** Bordered table shell; header stays put while the body scrolls inside the page scroll. */
@Composable
internal fun BillLinesFrame(content: @Composable ColumnScope.() -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(Color.White)
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .padding(4.dp),
        content = content,
    )
}

@Composable
private fun BillLineRetailRow(
    line: SaleLineDraft,
    scroll: ScrollState,
    showCounterPicker: Boolean = false,
    counterOptions: List<String> = emptyList(),
    onMoveToCounter: (String) -> Unit = {},
    onQty: (Int) -> Unit,
    onDiscPct: (Double) -> Unit,
    onDiscAmt: (Double) -> Unit,
    onBatch: () -> Unit,
    onRemove: () -> Unit,
) {
    var qtyText by remember(line.lineKey, line.qty) { mutableStateOf(line.qty.toString()) }
    var discPctText by remember(line.lineKey, line.discountPct) { mutableStateOf(line.discountPct.toString()) }
    val discDisplay = lineDiscAmt(line)
    var discAmtText by remember(line.lineKey, discDisplay) { mutableStateOf(discDisplay.toString()) }
    val packMrp = if (line.packMrp > 0) line.packMrp else line.rate * line.unitsPerPack.coerceAtLeast(1)
    val profit = lineProfitRetail(line)
    val batchModifier = if (line.batches.size > 1) {
        Modifier.clickable(onClick = onBatch)
    } else {
        Modifier
    }

    Row(
        Modifier
            .fillMaxWidth()
            .horizontalScroll(scroll)
            .heightIn(min = 44.dp)
            .padding(vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(BillRetailCols.gap),
    ) {
        BillGridCellText(
            line.productName,
            BillRetailCols.product,
        )
        if (showCounterPicker) {
            LineCounterPicker(
                options = counterOptions,
                onSelect = onMoveToCounter,
            )
        }
        BillGridCellText(line.mfg.ifBlank { "—" }.take(4), BillRetailCols.mfg)
        BillGridNumInput(
            qtyText,
            {
                qtyText = it.filter { c -> c.isDigit() }
                onQty(qtyText.toIntOrNull() ?: 1)
            },
            BillRetailCols.qty,
        )
        BillGridNumInput(
            discPctText,
            {
                discPctText = it
                onDiscPct(discPctText.toDoubleOrNull() ?: 0.0)
                discAmtText = lineDiscAmt(
                    syncDiscFromPct(line, discPctText.toDoubleOrNull() ?: 0.0),
                ).toString()
            },
            BillRetailCols.discPct,
        )
        Text(
            line.batchNo.ifBlank { "—" },
            Modifier
                .width(BillRetailCols.batch)
                .then(batchModifier)
                .padding(horizontal = 2.dp, vertical = 4.dp),
            style = MaterialTheme.typography.labelSmall,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            color = if (line.batches.size > 1) PharmaTeal else Color.Unspecified,
        )
        BillGridCellText(line.shelf.ifBlank { "—" }.take(4), BillRetailCols.shelf)
        BillGridCellText(formatInr(line.purchaseRate), BillRetailCols.purRate, alignEnd = true)
        BillGridCellText(formatPackExpiry(line.expiry), BillRetailCols.exp)
        BillGridCellText(line.schedule.take(4), BillRetailCols.sch)
        BillGridCellText(line.pack.ifBlank { "—" }, BillRetailCols.pack)
        BillGridCellText(formatInr(packMrp), BillRetailCols.mrp, alignEnd = true)
        BillGridCellText(formatInr(lineTaxableRetail(line)), BillRetailCols.taxable, alignEnd = true)
        BillGridNumInput(
            discAmtText,
            {
                discAmtText = it
                val amt = discAmtText.toDoubleOrNull() ?: 0.0
                onDiscAmt(amt)
                discPctText = syncDiscFromAmt(line, amt).discountPct.toString()
            },
            BillRetailCols.discAmt,
        )
        BillGridCellText(
            formatInr(profit),
            BillRetailCols.profit,
            alignEnd = true,
            color = if (profit < 0) MaterialTheme.colorScheme.error else Color(0xFF059669),
        )
        BillGridCellText(
            "${line.gstRate.toInt()}%",
            BillRetailCols.gst,
            alignEnd = true,
        )
        BillGridCellText(
            formatInr(lineNetRetail(line)),
            BillRetailCols.net,
            alignEnd = true,
            fontWeight = FontWeight.SemiBold,
        )
        IconButton(onClick = onRemove, modifier = Modifier.width(BillRetailCols.action).size(32.dp)) {
            Icon(Icons.Default.Close, contentDescription = "Remove", modifier = Modifier.size(16.dp))
        }
    }
}

@Composable
private fun BillLinesWholesaleGrid(
    lines: List<SaleLineDraft>,
    cdPct: Double,
    maxBodyHeight: Dp,
    onQty: (Long, Int) -> Unit,
    onFree: (Long, Int) -> Unit,
    onDisc: (Long, Double) -> Unit,
    onRate: (Long, Double) -> Unit,
    onBatch: (SaleLineDraft) -> Unit,
    onRemove: (Long) -> Unit,
) {
    val scroll = rememberScrollState()
    val bodyScroll = rememberScrollState()
    LaunchedEffect(lines.size) { bodyScroll.animateScrollTo(bodyScroll.maxValue) }
    val wProd = 120.dp
    val wNum = 52.dp
    val wBatch = 72.dp
    val wAct = 36.dp
    BillLinesFrame {
        Row(
            Modifier.fillMaxWidth().horizontalScroll(scroll).background(BillGridHeaderBg),
            horizontalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            BillGridTh("PRODUCT", wProd)
            BillGridTh("QTY", wNum, alignEnd = true) // strips
            BillGridTh("FREE", wNum, alignEnd = true)
            BillGridTh("DISC%", wNum, alignEnd = true)
            BillGridTh("BATCH", wBatch)
            BillGridTh("MRP", wNum, alignEnd = true) // strip MRP
            BillGridTh("RATE", wNum, alignEnd = true) // PTR/PTS per strip
            BillGridTh("TAX", wNum, alignEnd = true)
            BillGridTh("NET", wNum, alignEnd = true)
            BillGridTh("", wAct)
        }
        HorizontalDivider(color = PharmaBorder)
        Column(Modifier.heightIn(max = maxBodyHeight).verticalScroll(bodyScroll)) {
            lines.forEachIndexed { index, line ->
                BillLineWholesaleRow(
                    line = line,
                    cdPct = cdPct,
                    scroll = scroll,
                    wProd = wProd,
                    wNum = wNum,
                    wBatch = wBatch,
                    wAct = wAct,
                    onQty = { onQty(line.lineKey, it) },
                    onFree = { onFree(line.lineKey, it) },
                    onDisc = { onDisc(line.lineKey, it) },
                    onRate = { onRate(line.lineKey, it) },
                    onBatch = { onBatch(line) },
                    onRemove = { onRemove(line.lineKey) },
                )
                if (index < lines.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.5f))
            }
        }
    }
}

@Composable
private fun BillLineWholesaleRow(
    line: SaleLineDraft,
    cdPct: Double,
    scroll: ScrollState,
    wProd: Dp,
    wNum: Dp,
    wBatch: Dp,
    wAct: Dp,
    onQty: (Int) -> Unit,
    onFree: (Int) -> Unit,
    onDisc: (Double) -> Unit,
    onRate: (Double) -> Unit,
    onBatch: () -> Unit,
    onRemove: () -> Unit,
) {
    var qtyText by remember(line.lineKey, line.qty) { mutableStateOf(line.qty.toString()) }
    var freeText by remember(line.lineKey, line.freeQty) { mutableStateOf(line.freeQty.toString()) }
    var discText by remember(line.lineKey, line.discountPct) { mutableStateOf(line.discountPct.toString()) }
    var rateText by remember(line.lineKey, line.rate) { mutableStateOf(line.rate.toString()) }
    val batchClickable = line.batches.size > 1
    Row(
        Modifier
            .fillMaxWidth()
            .horizontalScroll(scroll)
            .heightIn(min = 44.dp)
            .padding(vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Column(Modifier.width(wProd)) {
            Text(
                line.productName,
                style = MaterialTheme.typography.labelSmall,
                fontWeight = FontWeight.SemiBold,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                color = PharmaSlate,
            )
            if (line.unitsPerPack > 1 || line.pack.isNotBlank()) {
                Text(
                    buildString {
                        if (line.pack.isNotBlank()) append(line.pack)
                        if (line.unitsPerPack > 1) {
                            if (isNotEmpty()) append(" · ")
                            append("${line.unitsPerPack}/strip")
                        }
                    },
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
        BillGridNumInput(qtyText, {
            qtyText = it.filter { c -> c.isDigit() }
            onQty(qtyText.toIntOrNull() ?: 1)
        }, wNum)
        BillGridNumInput(freeText, {
            freeText = it.filter { c -> c.isDigit() }
            onFree(freeText.toIntOrNull() ?: 0)
        }, wNum)
        BillGridNumInput(discText, {
            discText = it
            onDisc(discText.toDoubleOrNull() ?: 0.0)
        }, wNum)
        Text(
            line.batchNo.ifBlank { "—" },
            style = MaterialTheme.typography.labelSmall,
            color = if (batchClickable) PharmaTeal else PharmaSlate,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier
                .width(wBatch)
                .then(if (batchClickable) Modifier.clickable(onClick = onBatch) else Modifier),
        )
        BillGridCellText(formatInr(line.packMrp), wNum, alignEnd = true)
        BillGridNumInput(rateText, {
            rateText = it
            onRate(rateText.toDoubleOrNull() ?: 0.0)
        }, wNum)
        BillGridCellText(formatInr(lineTaxableWholesale(line, cdPct)), wNum, alignEnd = true)
        BillGridCellText(
            formatInr(lineNetWholesale(line, cdPct)),
            wNum,
            alignEnd = true,
            fontWeight = FontWeight.SemiBold,
        )
        IconButton(onClick = onRemove, modifier = Modifier.width(wAct).size(32.dp)) {
            Icon(Icons.Default.Close, contentDescription = "Remove", modifier = Modifier.size(16.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CompactSelectDropdown(
    label: String,
    value: String,
    expanded: Boolean,
    onExpandedChange: (Boolean) -> Unit,
    modifier: Modifier = Modifier,
    dense: Boolean = false,
    menuContent: @Composable ExposedDropdownMenuBoxScope.() -> Unit,
) {
    Column(modifier) {
        Text(
            label,
            style = MaterialTheme.typography.labelSmall,
            color = Color(0xFF64748B),
            maxLines = 1,
        )
        Spacer(Modifier.height(if (dense) 1.dp else 2.dp))
        ExposedDropdownMenuBox(
            expanded = expanded,
            onExpandedChange = onExpandedChange,
            modifier = Modifier.fillMaxWidth(),
        ) {
            if (dense) {
                Box(
                    modifier = Modifier
                        .menuAnchor()
                        .fillMaxWidth()
                        .defaultMinSize(minHeight = SalesDenseFieldHeight)
                        .clip(RoundedCornerShape(6.dp))
                        .border(1.dp, MaterialTheme.colorScheme.outline, RoundedCornerShape(6.dp))
                        .padding(start = 8.dp, end = 4.dp, top = 6.dp, bottom = 6.dp),
                ) {
                    Text(
                        text = value,
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaSlate,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier
                            .align(Alignment.CenterStart)
                            .padding(end = 22.dp),
                    )
                    ExposedDropdownMenuDefaults.TrailingIcon(
                        expanded = expanded,
                        modifier = Modifier.align(Alignment.CenterEnd),
                    )
                }
            } else {
                OutlinedTextField(
                    value = value,
                    onValueChange = {},
                    readOnly = true,
                    modifier = Modifier
                        .menuAnchor()
                        .fillMaxWidth()
                        .heightIn(min = CompactFieldHeight),
                    textStyle = MaterialTheme.typography.labelSmall.copy(color = PharmaSlate),
                    singleLine = true,
                    shape = RoundedCornerShape(8.dp),
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedTextColor = PharmaSlate,
                        unfocusedTextColor = PharmaSlate,
                        disabledTextColor = PharmaSlate,
                    ),
                    trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
                )
            }
            menuContent()
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AddProductSearchCard(
    billMode: String,
    wholesale: Boolean,
    saleType: String,
    onSaleType: (String) -> Unit,
    cashCounter: Boolean,
    addProductExpanded: Boolean,
    onToggleExpand: () -> Unit,
    catalogListExpanded: Boolean,
    onCatalogListExpanded: (Boolean) -> Unit,
    hits: List<JsonObject>,
    onPickProduct: (JsonObject) -> Unit,
    catalogMaxHeight: Dp,
    searchMode: ProductSearchMode,
    searchModeExpanded: Boolean,
    onSearchModeExpanded: (Boolean) -> Unit,
    onSearchMode: (ProductSearchMode) -> Unit,
    genericExpanded: Boolean,
    onGenericExpanded: (Boolean) -> Unit,
    genericLabel: String,
    generics: List<JsonObject>,
    onGenericPick: (JsonObject) -> Unit,
    generalStockOnly: Boolean,
    onGeneralStockOnly: (Boolean) -> Unit,
    query: String,
    onQuery: (String) -> Unit,
    onSearchFocus: (Boolean) -> Unit,
    searchLoading: Boolean,
    hitCount: Int,
) {
    var saleTypeExpanded by remember { mutableStateOf(false) }
    val saleTypeOptions = buildList {
        if (billMode != "wholesale") add("cash" to "Cash/Counter")
        add("regular" to "Regular")
        add("return" to "Return")
    }
    val saleTypeLabel = saleTypeOptions.firstOrNull { it.first == saleType }?.second ?: "Regular"
    val focusManager = LocalFocusManager.current
    val searchPlaceholder = when (searchMode) {
        ProductSearchMode.Name -> "Search by product name…"
        ProductSearchMode.Generic -> "Filter products in generic…"
    }

    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(Color.White)
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .padding(6.dp),
    ) {
        Row(
            Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Default.Search, contentDescription = null, tint = PharmaTeal, modifier = Modifier.size(14.dp))
            Text(
                "Add products",
                style = MaterialTheme.typography.labelSmall,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier.padding(start = 4.dp).weight(1f),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                when {
                    searchLoading -> "…"
                    hitCount == 0 -> "0"
                    else -> hitCount.toString()
                },
                style = MaterialTheme.typography.labelSmall,
                color = PharmaTeal,
                modifier = Modifier.padding(end = 2.dp),
            )
            IconButton(onClick = onToggleExpand, modifier = Modifier.size(28.dp)) {
                Icon(
                    if (addProductExpanded) Icons.Default.ExpandLess else Icons.Default.ExpandMore,
                    contentDescription = if (addProductExpanded) "Collapse products" else "Expand products",
                    modifier = Modifier.size(20.dp),
                    tint = PharmaSlate,
                )
            }
        }
        if (addProductExpanded) {
            Spacer(Modifier.height(4.dp))
            SalesSearchRow(
                value = query,
                onValueChange = onQuery,
                placeholder = searchPlaceholder,
                onSearch = {
                    onCatalogListExpanded(true)
                    focusManager.clearFocus()
                },
                leadingIcon = {
                    Icon(Icons.Default.Search, contentDescription = null, modifier = Modifier.size(16.dp), tint = PharmaTeal)
                },
                trailingIcon = {
                    if (query.isNotBlank()) {
                        IconButton(onClick = { onQuery("") }, modifier = Modifier.size(24.dp)) {
                            Icon(Icons.Default.Close, contentDescription = "Clear search", modifier = Modifier.size(14.dp))
                        }
                    } else if (searchLoading) {
                        CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                    }
                },
                onFocusChanged = { st ->
                    onSearchFocus(st.isFocused)
                    if (st.isFocused) onCatalogListExpanded(true)
                },
            )
            Spacer(Modifier.height(4.dp))
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                CompactSelectDropdown(
                    label = "Sale type",
                    value = saleTypeLabel,
                    expanded = saleTypeExpanded,
                    onExpandedChange = { if (!cashCounter) saleTypeExpanded = it },
                    modifier = Modifier.weight(1f),
                    dense = true,
                ) {
                    ExposedDropdownMenu(
                        expanded = saleTypeExpanded,
                        onDismissRequest = { saleTypeExpanded = false },
                    ) {
                        saleTypeOptions.forEach { (v, l) ->
                            DropdownMenuItem(
                                text = { Text(l, style = MaterialTheme.typography.bodySmall) },
                                onClick = {
                                    onSaleType(v)
                                    saleTypeExpanded = false
                                },
                            )
                        }
                    }
                }
                CompactSelectDropdown(
                    label = "Search by",
                    value = searchMode.shortLabel,
                    expanded = searchModeExpanded,
                    onExpandedChange = onSearchModeExpanded,
                    modifier = Modifier.weight(1f),
                    dense = true,
                ) {
                    ExposedDropdownMenu(
                        expanded = searchModeExpanded,
                        onDismissRequest = { onSearchModeExpanded(false) },
                    ) {
                        ProductSearchMode.entries.forEach { mode ->
                            DropdownMenuItem(
                                text = { Text(mode.label, style = MaterialTheme.typography.bodySmall) },
                                onClick = { onSearchMode(mode) },
                            )
                        }
                    }
                }
            }
            if (searchMode == ProductSearchMode.Generic) {
                Spacer(Modifier.height(4.dp))
                CompactSelectDropdown(
                    label = "Generic",
                    value = genericLabel,
                    expanded = genericExpanded,
                    onExpandedChange = onGenericExpanded,
                    modifier = Modifier.fillMaxWidth(),
                    dense = true,
                ) {
                    ExposedDropdownMenu(
                        expanded = genericExpanded,
                        onDismissRequest = { onGenericExpanded(false) },
                    ) {
                        generics.take(80).forEach { g ->
                            DropdownMenuItem(
                                text = { Text(g.str("name"), style = MaterialTheme.typography.bodySmall) },
                                onClick = { onGenericPick(g) },
                            )
                        }
                    }
                }
            }
            Row(
                Modifier.fillMaxWidth().padding(top = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Checkbox(
                    checked = generalStockOnly,
                    onCheckedChange = onGeneralStockOnly,
                    modifier = Modifier.size(28.dp),
                )
                Text("General stock only", style = MaterialTheme.typography.labelSmall, maxLines = 1)
            }
            Spacer(Modifier.height(4.dp))
            HorizontalDivider(color = PharmaBorder.copy(alpha = 0.5f))
            Spacer(Modifier.height(2.dp))
            if (catalogListExpanded) {
                ProductCatalogList(
                    hits = hits,
                    searchLoading = searchLoading,
                    maxHeight = catalogMaxHeight,
                    wholesale = wholesale,
                    onPick = onPickProduct,
                    onCollapse = { onCatalogListExpanded(false) },
                )
            } else {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clickable { onCatalogListExpanded(true) }
                        .padding(vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        "Show list (${if (searchLoading) "…" else hitCount})",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaTeal,
                        modifier = Modifier.weight(1f),
                    )
                    Icon(
                        Icons.Default.ExpandMore,
                        contentDescription = "Expand product list",
                        tint = PharmaSlate,
                        modifier = Modifier.size(18.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun ProductCatalogList(
    hits: List<JsonObject>,
    searchLoading: Boolean,
    maxHeight: Dp,
    wholesale: Boolean,
    onPick: (JsonObject) -> Unit,
    onCollapse: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier.fillMaxWidth()) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text("Catalog", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.weight(1f))
            when {
                searchLoading -> CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                hits.isEmpty() -> Text("No matches", style = MaterialTheme.typography.labelSmall)
                else -> Text("${hits.size} items", style = MaterialTheme.typography.labelSmall, color = PharmaTeal)
            }
            IconButton(onClick = onCollapse, modifier = Modifier.size(32.dp)) {
                Icon(
                    Icons.Default.ExpandLess,
                    contentDescription = "Collapse product list",
                    modifier = Modifier.size(22.dp),
                    tint = PharmaSlate,
                )
            }
        }
        Spacer(Modifier.height(4.dp))
        when {
            searchLoading && hits.isEmpty() -> {
                Text("Loading catalog…", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(8.dp))
            }
            hits.isEmpty() -> {
                Text(
                    "Try another name or clear filters.",
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(8.dp),
                )
            }
            else -> {
                val catalogScroll = rememberScrollState()
                Column(
                    verticalArrangement = Arrangement.spacedBy(4.dp),
                    modifier = Modifier
                        .fillMaxWidth()
                        .heightIn(min = 48.dp, max = maxHeight)
                        .verticalScroll(catalogScroll),
                ) {
                    hits.take(50).forEach { p ->
                        ProductHitRow(p, wholesale = wholesale, onClick = { onPick(p) })
                    }
                    if (hits.size > 50) {
                        Text(
                            "+ ${hits.size - 50} more — narrow your search",
                            style = MaterialTheme.typography.labelSmall,
                            modifier = Modifier.padding(4.dp),
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ProductHitRow(p: JsonObject, wholesale: Boolean, onClick: () -> Unit) {
    val name = p.str("product_name").ifBlank { p.str("name") }
    val upp = p.jInt("units_per_pack").coerceAtLeast(1)
    val mrp = if (wholesale) {
        // Prefer strip MRP for wholesale catalog (web sidebarProductMrp).
        val pack = p.amount("pack_mrp").takeIf { it > 0 } ?: p.amount("mrp")
        val unit = p.amount("unit_mrp").takeIf { it > 0 } ?: productSearchMrp(p)
        when {
            pack > 0 && upp > 1 && unit > 0 && kotlin.math.abs(pack - unit) < 0.02 -> unit * upp
            pack > 0 -> pack
            upp > 1 && unit > 0 && unit <= 15.0 -> unit * upp
            else -> unit
        }
    } else {
        productSearchMrp(p)
    }
    val stockUnits = p.jInt("available_qty")
    val strips = if (wholesale) stockUnits / upp else stockUnits
    val schedule = p.str("schedule_category").ifBlank { "OTC" }
    Surface(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        shape = MaterialTheme.shapes.small,
        color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.35f),
    ) {
        Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(name, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(
                    buildString {
                        append("Sch $schedule")
                        if (wholesale && upp > 1) append(" · $upp/strip")
                    },
                    style = MaterialTheme.typography.labelSmall,
                )
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(
                    if (wholesale) "${formatMrp(mrp)}/strip" else formatMrp(mrp),
                    style = MaterialTheme.typography.titleSmall,
                    color = PharmaTeal,
                )
                Text(
                    when {
                        strips <= 0 -> "No stock"
                        wholesale -> "$strips strip${if (strips == 1) "" else "s"}"
                        else -> "$stockUnits in stock"
                    },
                    style = MaterialTheme.typography.labelSmall,
                    color = if (strips > 0) PharmaTeal else MaterialTheme.colorScheme.error,
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun BatchPickerSheet(
    productName: String,
    batches: List<JsonObject>,
    wholesale: Boolean = false,
    onDismiss: () -> Unit,
    onSelect: (JsonObject) -> Unit,
) {
    ModalBottomSheet(onDismissRequest = onDismiss) {
        LazyColumn(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            item {
                Text("Select batch", style = MaterialTheme.typography.titleMedium)
                Text(
                    if (wholesale) "$productName · stock in strips" else productName,
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            items(batches) { batch ->
                val qty = batchStockQty(batch)
                val upp = batch.jInt("units_per_pack").coerceAtLeast(1)
                val strips = qty / upp
                val qtyLabel = when {
                    qty <= 0 -> "Qty 0"
                    wholesale && upp > 1 -> "$strips strip${if (strips == 1) "" else "s"} ($qty units)"
                    upp > 1 -> "Qty $qty ($strips pack${if (strips == 1) "" else "s"})"
                    else -> "Qty $qty"
                }
                val mrp = if (wholesale) {
                    wholesaleStripMrpFromBatch(batch, upp)
                } else {
                    batch.amount("pack_mrp").takeIf { it > 0 }
                        ?: batch.amount("unit_mrp").takeIf { it > 0 }
                        ?: batch.amount("mrp")
                }
                val selectable = if (wholesale) strips > 0 else qty > 0
                PharmaCard(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable(enabled = selectable) { onSelect(batch) },
                ) {
                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("Batch ${batch.str("batch_no")}", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
                            Text(
                                "Exp ${formatPackExpiry(batch.str("expiry_date"))} · MRP ${formatInr(mrp)}${if (wholesale) "/strip" else ""}",
                                style = MaterialTheme.typography.labelSmall,
                                color = PharmaMuted,
                            )
                        }
                        Text(
                            qtyLabel,
                            style = MaterialTheme.typography.labelMedium,
                            fontWeight = FontWeight.Bold,
                            color = if (selectable) PharmaTeal else MaterialTheme.colorScheme.error,
                        )
                    }
                }
            }
            item { Spacer(Modifier.height(24.dp)) }
        }
    }
}
