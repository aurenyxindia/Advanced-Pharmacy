package `in`.paperlesspharmacy.advanced.ui.screens

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import `in`.paperlesspharmacy.advanced.util.DownloadNotifier
import `in`.paperlesspharmacy.advanced.util.ReportExcelWriter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate
import kotlin.math.abs

private const val XLS_MIME = "application/vnd.ms-excel"

private fun r2(v: Double): Double = Math.round(v * 100) / 100.0

private fun dcMoney(v: Double): String = rptMoney(v)

private fun diffColor(d: Double): Color = when {
    abs(d) < 0.005 -> Color(0xFF15803D)
    d < 0 -> Color(0xFFDC2626)
    else -> Color(0xFFD97706)
}

private fun openXls(context: Context, uri: Uri): String? =
    runCatching {
        context.startActivity(
            Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, XLS_MIME)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
            },
        )
    }.exceptionOrNull()?.let { "No app found to open Excel files. The file is saved in Downloads." }

/** Day-end WhatsApp outcome, worded like the web toast. Returns (message, isWarning). */
private fun dayEndMessage(res: JsonObject): Pair<String, Boolean> {
    val wa = res.obj("data").obj("whatsapp")
    val base = res.str("message").ifBlank { "Day End value saved successfully!" }
    if (wa.size() == 0 || wa.bool("ok")) return base to false
    val msg = when (wa.str("skipped")) {
        "no_phone" -> "Day End saved. WhatsApp not sent — add mobile or alternate number in My Profile."
        "no_client" -> "Day End saved. WhatsApp not sent — client record not found."
        "not_configured" -> "Day End saved. WhatsApp not sent — API not configured."
        else -> "Day End saved. WhatsApp not sent: ${wa.str("error").ifBlank { "API error" }}"
    }
    return msg to true
}

@Composable
fun DailyClosingScreen(vm: AppViewModel, onBack: () -> Unit) {
    val repo = remember { vm.returns() }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val notifyGate = rememberNotifyPermissionGate()
    val canClose = vm.canEditModule("DAILY_CLOSING")
    val today = remember { LocalDate.now().toString() }

    var date by rememberSaveableStr(today)
    var summary by remember { mutableStateOf<JsonObject?>(null) }
    var loading by remember { mutableStateOf(true) }
    var saving by remember { mutableStateOf(false) }
    var cashText by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var warn by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var confirm by remember { mutableStateOf(false) }
    var downloaded by remember { mutableStateOf<Pair<Uri, String>?>(null) }

    var from by rememberSaveableStr(today.take(8) + "01")
    var to by rememberSaveableStr(today)
    var reportRows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var reportLoading by remember { mutableStateOf(false) }
    var exporting by remember { mutableStateOf(false) }

    fun load() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.closingSummary(date) } }
                .onSuccess { s ->
                    summary = s
                    val closing = s.obj("closing")
                    val saved = if (closing.has("available_cash") && !closing.get("available_cash").isJsonNull) closing.amount("available_cash") else null
                    cashText = rptMoney(saved ?: s.amount("expected_cash")).replace(",", "")
                }
                .onFailure { error = careErr(it, "Could not load closing summary.") }
            loading = false
        }
    }

    fun loadReport() {
        if (from > to) { error = "From date cannot be after To date."; return }
        scope.launch {
            reportLoading = true
            runCatching { withContext(Dispatchers.IO) { repo.closingReport(from, to) } }
                .onSuccess { reportRows = it }
                .onFailure { error = careErr(it, "Could not load closing report.") }
            reportLoading = false
        }
    }

    fun download(fileName: String, fetch: () -> ByteArray) = notifyGate {
        scope.launch {
            exporting = true
            runCatching {
                withContext(Dispatchers.IO) {
                    val bytes = fetch()
                    ReportExcelWriter.saveToDownloads(context, fileName, bytes, XLS_MIME) to fileName
                }
            }.onSuccess { saved ->
                downloaded = saved
                DownloadNotifier.notifyDownloaded(context, saved.first, saved.second, XLS_MIME)
            }.onFailure { error = careErr(it, "Could not download Excel.") }
            exporting = false
        }
    }

    LaunchedEffect(date) { load() }
    LaunchedEffect(Unit) { loadReport() }

    val s = summary
    val sales = s?.obj("sales") ?: JsonObject()
    val expected = s?.amount("expected_cash") ?: 0.0
    val available = cashText.toDoubleOrNull()
    val difference = r2((available ?: 0.0) - expected)
    val netSales = if (sales.has("net")) sales.amount("net") else sales.amount("total")
    val closed = s?.obj("closing")?.size()?.let { it > 0 } == true
    val wide = LocalConfiguration.current.screenWidthDp >= 600

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PoHeader("Daily Closing", "End-of-day cash reconcile", onBack) {
            if (closed) CareBadge("Closed", Color(0xFF15803D))
            IconButton(onClick = { load(); loadReport() }) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
        }
        error?.let { ErrorBanner(it) { error = null } }
        warn?.let { msg ->
            Surface(color = Color(0xFFFEF3C7), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                Row(Modifier.padding(start = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.Warning, null, Modifier.size(16.dp), tint = Color(0xFFB45309))
                    Spacer(Modifier.width(6.dp))
                    Text(msg, Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = Color(0xFF78350F))
                    TextButton(onClick = { warn = null }) { Text("OK", style = MaterialTheme.typography.labelSmall) }
                }
            }
        }
        toast?.let { PoToast(it) { toast = null } }
        downloaded?.let { (uri, name) ->
            RptDownloadedBar(name, onOpen = { openXls(context, uri)?.let { error = it } }, onDismiss = { downloaded = null })
        }

        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                PDateField("Closing date", date, { if (it.isNotBlank()) date = it }, Modifier.weight(1f))
                if (date != today) PharmaOutlinedButton("Today", Modifier.heightIn(max = 34.dp)) { date = today }
            }

            if (loading && s == null) LoadingBlock("Loading closing summary…")
            else if (s != null) {
                CareKpiGrid(
                    listOf(
                        CareKpiItem("Net Sales", careMoney(netSales), PharmaTeal, Icons.Default.TrendingUp),
                        CareKpiItem("Total Cash", careMoney(expected), PharmaSlate, Icons.Default.Payments),
                        CareKpiItem("Today's Customers", sales.jInt("customers").toString(), Color(0xFF2563EB), Icons.Default.People),
                        CareKpiItem("Cash Difference", careMoney(difference), diffColor(difference), Icons.Default.Balance),
                    ),
                )

                val cp = s.obj("customer_payment")
                val dp = s.obj("debit_payment")
                val cards: List<@Composable (Modifier) -> Unit> = listOf(
                    { m ->
                        DcCard("Sales", Icons.Default.ShoppingCart, m,
                            rows = listOf("Cash" to sales.amount("cash"), "UPI" to sales.amount("upi"), "Card" to sales.amount("card"), "Credit" to sales.amount("credit"), "Return" to sales.amount("return")),
                            foot = listOf("Total Sales" to dcMoney(sales.amount("total")), "Net Sales" to dcMoney(netSales), "Today Customers" to sales.jInt("customers").toString()),
                        )
                    },
                    { m ->
                        DcCard("Customer Payment", Icons.Default.People, m,
                            rows = listOf("Cash" to cp.amount("cash"), "Card" to cp.amount("card"), "Cheque" to cp.amount("cheque")),
                            foot = listOf("Total Payment" to dcMoney(cp.amount("total"))),
                        )
                    },
                    { m ->
                        DcCashCard(m, expected, cashText, { cashText = it }, difference, enabled = canClose)
                    },
                    { m ->
                        DcCard("Debited Payment", Icons.Default.ArrowCircleDown, m,
                            rows = listOf("Cash" to dp.amount("cash"), "Card" to dp.amount("card"), "Cheque" to dp.amount("cheque")),
                            foot = listOf("Total Debit" to dcMoney(dp.amount("total"))),
                        )
                    },
                )
                if (wide) {
                    cards.chunked(2).forEach { pair ->
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) { pair.forEach { c -> c(Modifier.weight(1f)) } }
                    }
                } else cards.forEach { c -> c(Modifier.fillMaxWidth()) }

                Text(
                    "Completing day end sends WhatsApp template daily_closing to profile phone and alternate phone (My Profile).",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    PharmaOutlinedButton(if (exporting) "Exporting…" else "Export Day", Modifier.weight(1f), enabled = !exporting) {
                        download("daily_closing_$date.xls") { repo.closingExportRow(date) }
                    }
                    if (canClose) {
                        PharmaPrimaryButton(if (saving) "Saving…" else "Complete Day End", Modifier.weight(1.4f), enabled = !saving && !loading) {
                            if (available == null || available < 0) error = "Enter a valid available cash amount."
                            else confirm = true
                        }
                    }
                }
                if (!canClose) Text("View only — you need Daily Closing edit rights to complete day end.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }

            DcReportSection(
                from = from,
                to = to,
                onFrom = { from = it },
                onTo = { to = it },
                rows = reportRows,
                loading = reportLoading,
                exporting = exporting,
                onLoad = { loadReport() },
                onExport = {
                    if (reportRows.isEmpty()) error = "Load a report first."
                    else download("daily_closing_report_${from}_to_$to.xls") { repo.closingExport(from, to) }
                },
                onExportRow = { d -> download("daily_closing_$d.xls") { repo.closingExportRow(d) } },
                onOpenDay = { d -> date = d },
            )
        }
    }

    if (confirm) {
        CareConfirmDialog(
            title = "Complete day end?",
            text = buildString {
                append("Closing date: ${careDate(date)}\n")
                append("Total cash: ${careMoney(expected)}\n")
                append("Available cash: ${careMoney(available ?: 0.0)}\n")
                append("Difference: ${careMoney(difference)}")
                if (closed) append("\n\nThis day is already closed — saving will update it.")
            },
            confirmLabel = "Complete",
            danger = false,
            onConfirm = {
                confirm = false
                saving = true
                scope.launch {
                    try {
                        runCatching { withContext(Dispatchers.IO) { repo.dayEnd(date, r2(available ?: 0.0)) } }
                            .onSuccess { res ->
                                val (msg, isWarn) = dayEndMessage(res)
                                if (isWarn) warn = msg else toast = msg
                                load()
                                loadReport()
                            }
                            .onFailure { error = careErr(it, "Could not close the day.") }
                    } finally {
                        saving = false
                    }
                }
            },
            onDismiss = { confirm = false },
        )
    }
}

@Composable
private fun rememberSaveableStr(initial: String): MutableState<String> =
    androidx.compose.runtime.saveable.rememberSaveable { mutableStateOf(initial) }

@Composable
private fun DcCardShell(title: String, icon: ImageVector, modifier: Modifier, content: @Composable ColumnScope.() -> Unit) {
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = modifier) {
        Column {
            Row(
                Modifier.fillMaxWidth().background(PharmaTeal.copy(alpha = 0.08f)).padding(horizontal = 10.dp, vertical = 7.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(icon, null, Modifier.size(16.dp), tint = PharmaTealDark)
                Spacer(Modifier.width(6.dp))
                Text(title, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaTealDark)
            }
            Column(Modifier.padding(horizontal = 10.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(3.dp), content = content)
        }
    }
}

@Composable
private fun DcLine(label: String, value: String, bold: Boolean = false, color: Color = PharmaSlate) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label.uppercase(), Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = if (bold) PharmaSlate else PharmaMuted, fontWeight = if (bold) FontWeight.SemiBold else FontWeight.Normal)
        Text(value, style = MaterialTheme.typography.bodySmall, fontWeight = if (bold) FontWeight.Bold else FontWeight.SemiBold, color = color)
    }
}

@Composable
private fun DcCard(title: String, icon: ImageVector, modifier: Modifier, rows: List<Pair<String, Double>>, foot: List<Pair<String, String>>) {
    DcCardShell(title, icon, modifier) {
        rows.forEach { (l, v) -> DcLine(l, dcMoney(v)) }
        HorizontalDivider(Modifier.padding(vertical = 3.dp), color = PharmaBorder)
        foot.forEach { (l, v) -> DcLine(l, v, bold = true) }
    }
}

@Composable
private fun DcCashCard(modifier: Modifier, expected: Double, cash: String, onCash: (String) -> Unit, difference: Double, enabled: Boolean) {
    DcCardShell("Cash Adjustment", Icons.Default.Straighten, modifier) {
        DcLine("Total Cash", dcMoney(expected))
        if (enabled) {
            SalesFormField(
                "Available Cash",
                cash,
                { v -> if (v.isEmpty() || Regex("""^\d{0,9}(\.\d{0,2})?$""").matches(v)) onCash(v) },
                Modifier.fillMaxWidth(),
                keyboardType = KeyboardType.Decimal,
                isError = cash.isNotBlank() && cash.toDoubleOrNull() == null,
            )
            Text("Prefilled with Total Cash — change only if the drawer count differs.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        } else {
            PReadField("Available Cash", cash, Modifier.fillMaxWidth())
        }
        HorizontalDivider(Modifier.padding(vertical = 3.dp), color = PharmaBorder)
        DcLine("Difference", dcMoney(difference), bold = true, color = diffColor(difference))
    }
}

@Composable
private fun DcReportSection(
    from: String,
    to: String,
    onFrom: (String) -> Unit,
    onTo: (String) -> Unit,
    rows: List<JsonObject>,
    loading: Boolean,
    exporting: Boolean,
    onLoad: () -> Unit,
    onExport: () -> Unit,
    onExportRow: (String) -> Unit,
    onOpenDay: (String) -> Unit,
) {
    DcCardShell("Daily Closing Report", Icons.Default.TableChart, Modifier.fillMaxWidth()) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PDateField("From date", from, { if (it.isNotBlank()) onFrom(it) }, Modifier.weight(1f))
            PDateField("To date", to, { if (it.isNotBlank()) onTo(it) }, Modifier.weight(1f))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(top = 4.dp)) {
            PharmaPrimaryButton(if (loading) "Loading…" else "Load Report", Modifier.weight(1f), enabled = !loading) { onLoad() }
            PharmaOutlinedButton("Export Excel", Modifier.weight(1f), enabled = !exporting) { onExport() }
        }
        if (rows.isNotEmpty()) {
            val diffSum = rows.sumOf { it.amount("difference") }
            Row(Modifier.fillMaxWidth().padding(top = 4.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                SalesLogKpiChip("Days", rows.size.toString(), Modifier.weight(1f))
                SalesLogKpiChip("Net", dcMoney(rows.sumOf { if (it.has("net_sales") && !it.get("net_sales").isJsonNull) it.amount("net_sales") else it.amount("total_sales") }), Modifier.weight(1f))
                SalesLogKpiChip("Diff", dcMoney(diffSum), Modifier.weight(1f))
            }
        }
        when {
            loading -> LoadingBlock("Loading report…")
            rows.isEmpty() -> CareEmpty(Icons.Default.Inbox, "No closings in range.")
            else -> rows.forEach { r -> DcReportRow(r, onExportRow, onOpenDay) }
        }
    }
}

@Composable
private fun DcReportRow(r: JsonObject, onExportRow: (String) -> Unit, onOpenDay: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val d = r.str("closing_date").take(10)
    val diff = r.amount("difference")
    val net = if (r.has("net_sales") && !r.get("net_sales").isJsonNull) r.amount("net_sales") else r.amount("total_sales")
    Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth().padding(top = 4.dp)) {
        Column(Modifier.clickable { open = !open }.padding(horizontal = 8.dp, vertical = 6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(careDate(d), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
                    Text(
                        "Net ${dcMoney(net)} · Cash ${dcMoney(r.amount("expected_cash"))} · ${r.jInt("today_customers")} cust",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
                Text(dcMoney(diff), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = diffColor(diff))
                IconButton(onClick = { onExportRow(d) }, Modifier.size(32.dp)) { Icon(Icons.Default.Download, "Download day Excel", Modifier.size(17.dp), tint = PharmaTeal) }
                Icon(if (open) Icons.Default.ExpandLess else Icons.Default.ExpandMore, null, Modifier.size(18.dp), tint = PharmaMuted)
            }
            if (open) {
                Spacer(Modifier.height(4.dp))
                val pairs = listOf(
                    "Sales Cash" to dcMoney(r.amount("sales_cash")),
                    "UPI" to dcMoney(r.amount("sales_upi")),
                    "Card" to dcMoney(r.amount("sales_card")),
                    "Credit" to dcMoney(r.amount("sales_credit")),
                    "Total" to dcMoney(r.amount("total_sales")),
                    "Return" to dcMoney(r.amount("retail_return")),
                    "Net" to dcMoney(net),
                    "Cust Pmt" to dcMoney(r.amount("customer_payment")),
                    "Debit" to dcMoney(r.amount("debit_payment")),
                    "Customers" to r.jInt("today_customers").toString(),
                    "Total Cash" to dcMoney(r.amount("expected_cash")),
                    "Available" to dcMoney(r.amount("available_cash")),
                )
                pairs.chunked(3).forEach { row ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
                        row.forEach { (l, v) -> CareInfo(l, v, Modifier.weight(1f)) }
                        repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                    }
                }
                Text("Open this day", style = MaterialTheme.typography.labelMedium, color = PharmaTeal, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 2.dp).clickable { onOpenDay(d) })
            }
        }
    }
}
