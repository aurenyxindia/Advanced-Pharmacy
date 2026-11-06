package `in`.paperlesspharmacy.advanced.ui.screens

import android.Manifest
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import `in`.paperlesspharmacy.advanced.util.DownloadNotifier
import android.graphics.Paint
import android.graphics.Typeface
import android.graphics.pdf.PdfDocument
import android.net.Uri
import android.text.TextPaint
import android.text.TextUtils
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.core.content.FileProvider
import com.google.gson.JsonArray
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import `in`.paperlesspharmacy.advanced.ui.util.formatSaleDate
import `in`.paperlesspharmacy.advanced.util.PdfWifiPrint
import `in`.paperlesspharmacy.advanced.util.ReportExcelWriter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.text.NumberFormat
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlin.math.floor
import kotlin.math.max
import kotlin.math.round

// ───────────────────────── Config (assets/reports_config.json, exported from web reports.ts) ─────────────────────────

data class RptColumn(val key: String, val label: String, val type: String, val alignEnd: Boolean)

data class RptEntity(val source: String, val param: String, val label: String, val optional: Boolean)

data class RptDef(
    val key: String,
    val label: String,
    val path: String,
    val hasDate: Boolean,
    val hasMonth: Boolean,
    val hasMonths: Boolean,
    val hasMargin: Boolean,
    val category: String,
    val group: String,
    val entity: RptEntity?,
)

data class RptCategory(val id: String, val name: String, val color: Color)

class RptConfig(
    val categories: List<RptCategory>,
    val reports: List<RptDef>,
    val columns: Map<String, List<RptColumn>>,
    val detailColumns: Map<String, List<RptColumn>>,
) {
    fun find(keyOrPath: String): RptDef? =
        reports.firstOrNull { it.path == keyOrPath } ?: reports.firstOrNull { it.key == keyOrPath }
}

private var rptConfigCache: RptConfig? = null

private fun JsonObject.rs(key: String): String = get(key)?.takeIf { it.isJsonPrimitive }?.asString ?: ""
private fun JsonObject.rb(key: String): Boolean = get(key)?.takeIf { it.isJsonPrimitive }?.asBoolean ?: false

private fun parseCols(arr: JsonArray?): List<RptColumn> =
    arr?.mapNotNull { el ->
        val o = el.takeIf { it.isJsonObject }?.asJsonObject ?: return@mapNotNull null
        val type = o.rs("type")
        RptColumn(o.rs("key"), o.rs("label"), type, o.rs("align") == "end" || type == "currency" || type == "number")
    } ?: emptyList()

fun loadReportConfig(context: Context): RptConfig {
    rptConfigCache?.let { return it }
    val root = context.assets.open("reports_config.json").bufferedReader().use { JsonParser.parseReader(it).asJsonObject }
    val cats = root.getAsJsonArray("categories").map { el ->
        val o = el.asJsonObject
        RptCategory(o.rs("id"), o.rs("name"), Color(android.graphics.Color.parseColor(o.rs("color").ifBlank { "#0d9488" })))
    }
    val reports = root.getAsJsonArray("reports").map { el ->
        val o = el.asJsonObject
        val ef = o.get("entityFilter")?.takeIf { it.isJsonObject }?.asJsonObject
        RptDef(
            key = o.rs("key"),
            label = o.rs("label"),
            path = o.rs("path"),
            hasDate = o.rb("hasDate"),
            hasMonth = o.rb("hasMonth"),
            hasMonths = o.rb("hasMonths"),
            hasMargin = o.rb("hasMargin"),
            category = o.rs("category"),
            group = o.rs("group"),
            entity = ef?.let { RptEntity(it.rs("source"), it.rs("param"), it.rs("label"), it.rb("optional")) },
        )
    }
    fun colMap(key: String): Map<String, List<RptColumn>> =
        root.getAsJsonObject(key)?.entrySet()?.associate { (k, v) -> k to parseCols(v.takeIf { it.isJsonArray }?.asJsonArray) } ?: emptyMap()
    return RptConfig(cats, reports, colMap("columns"), colMap("detailColumns")).also { rptConfigCache = it }
}

// ───────────────────────── Formatting / totals (parity with web cell() + buildFooterRaw) ─────────────────────────

private val rptLocale = Locale("en", "IN")
private val rptDateRe = Regex("""^(\d{4})-(\d{2})-(\d{2})""")
private val rptYm = Regex("""^\d{4}-\d{2}$""")
private val rptNonSummable = Regex("""(^rate$|_rate$|^mrp$|margin_pct|gst_rate|days_to_expiry|_pct$|percent)""")

private fun rptNum(n: Double, minF: Int, maxF: Int): String =
    NumberFormat.getNumberInstance(rptLocale).apply {
        minimumFractionDigits = minF
        maximumFractionDigits = maxF
    }.format(n)

private fun isWhole(n: Double) = n.isFinite() && n == floor(n)

private fun rptRaw(row: JsonObject, key: String): String? {
    val el: JsonElement = row.get(key) ?: return null
    if (el.isJsonNull) return null
    val s = if (el.isJsonPrimitive) el.asString else el.toString()
    return s.ifEmpty { null }
}

internal fun rptMoney(v: Double): String = rptNum(v, 2, 2)

fun rptCell(row: JsonObject, col: RptColumn): String {
    val raw = rptRaw(row, col.key) ?: return "-"
    when (col.type) {
        "currency" -> return raw.trim().toDoubleOrNull()?.let { rptMoney(it) } ?: "NaN"
        "number" -> {
            val n = raw.trim().toDoubleOrNull() ?: return raw
            val frac = if (isWhole(n)) 0 else if (raw.contains('.') && raw.substringAfter('.').length == 3) 3 else 2
            return rptNum(n, frac, max(frac, 4))
        }
        "expiry" -> {
            val t = raw.trim()
            if (rptYm.matches(t)) return t
            if (rptDateRe.containsMatchIn(t)) return t.take(7)
            return t
        }
        "date" -> {
            val t = raw.trim()
            val m = rptDateRe.find(t) ?: return t
            return "${m.groupValues[3]}-${m.groupValues[2]}-${m.groupValues[1]}"
        }
    }
    return raw
}

private fun isSummable(col: RptColumn): Boolean {
    if (rptNonSummable.containsMatchIn(col.key.lowercase())) return false
    return col.type == "currency" || col.type == "number"
}

/** Totals row with raw sums (Double), "Total" label and blanks; null when nothing is summable. */
fun rptFooterRaw(cols: List<RptColumn>, rows: List<JsonObject>): List<Any>? {
    if (rows.isEmpty() || cols.isEmpty()) return null
    val summable = cols.map { isSummable(it) }
    if (summable.none { it }) return null
    var labelPlaced = false
    val out = cols.mapIndexed { i, col ->
        if (summable[i]) {
            var sum = 0.0
            var any = false
            rows.forEach { r -> rptRaw(r, col.key)?.trim()?.toDoubleOrNull()?.let { sum += it; any = true } }
            if (any) round(sum * 100) / 100 else ""
        } else if (!labelPlaced) {
            labelPlaced = true
            "Total"
        } else ""
    }.toMutableList<Any>()
    if (!labelPlaced) out[0] = "Total"
    return out
}

/** Totals row (formatted) or null when nothing is summable. */
fun rptFooter(cols: List<RptColumn>, rows: List<JsonObject>): List<String>? =
    rptFooterRaw(cols, rows)?.mapIndexed { i, v ->
        if (v is Double) {
            if (cols[i].type == "currency") rptMoney(v) else rptNum(v, if (isWhole(v)) 0 else 2, 2)
        } else v.toString()
    }

private fun badgeColors(v: String): Pair<Color, Color> = when {
    v == "Edited" -> Color(0xFFFEF3C7) to Color(0xFF92400E)
    v == "Added" -> Color(0xFFDCFCE7) to Color(0xFF166534)
    v == "Removed" -> Color(0xFFFEE2E2) to Color(0xFF991B1B)
    v.startsWith("Yes ·") -> Color(0xFFFEE2E2) to Color(0xFF991B1B)
    else -> Color(0xFFF1F5F9) to PharmaSlate
}

/** Table rows for a report (gst = single KPI row, profit = by_product). */
fun rptRows(def: RptDef, data: JsonObject?): List<JsonObject> {
    if (data == null) return emptyList()
    if (def.key == "gst") {
        return listOf(JsonObject().apply {
            add("output_gst", data.get("output_gst"))
            add("input_gst", data.get("input_gst"))
            add("net_payable", data.get("net_payable"))
        })
    }
    if (def.key == "profit" && data.get("by_product")?.isJsonArray == true) {
        return data.getAsJsonArray("by_product").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
    }
    return data.arr("rows").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
}

private fun rptColumns(cfg: RptConfig, def: RptDef, entityValue: String, data: JsonObject?): List<RptColumn> {
    val detail = cfg.detailColumns[def.key]
    if (detail != null && (entityValue.isNotBlank() || data?.rs("mode") == "detail")) return detail
    return cfg.columns[def.key] ?: emptyList()
}

// ───────────────────────── Params (parity with web buildParams) ─────────────────────────

data class RptFilters(
    val from: String = LocalDate.now().withDayOfMonth(1).toString(),
    val to: String = LocalDate.now().toString(),
    val month: String = YearMonth.now().toString(),
    val months: Int = 3,
    val marginPct: Double = 0.0,
    val branchId: Int? = null,
    val entityValue: String = "",
)

private fun rptParams(def: RptDef, f: RptFilters, isHo: Boolean): Map<String, String> {
    val p = linkedMapOf<String, String>()
    if (def.hasMonth) p["month"] = f.month.ifBlank { YearMonth.now().toString() }
    else if (def.hasDate) {
        if (f.from.isNotBlank()) p["from"] = f.from
        if (f.to.isNotBlank()) p["to"] = f.to
    }
    if (isHo && f.branchId != null) p["branch_id"] = f.branchId.toString()
    if (def.key == "asof") p["date"] = f.to.ifBlank { LocalDate.now().toString() }
    if (def.hasMonths) p["months"] = (if (f.months > 0) f.months else 3).toString()
    if (def.hasMargin && f.marginPct != 0.0) {
        p["margin_pct"] = if (isWhole(f.marginPct)) f.marginPct.toLong().toString() else f.marginPct.toString()
    }
    def.entity?.let { ef -> f.entityValue.trim().takeIf { it.isNotEmpty() }?.let { p[ef.param] = it } }
    return p
}

private fun fetchReport(vm: AppViewModel, def: RptDef, params: Map<String, String>): JsonObject {
    val res = vm.api.get("reports/${def.path}", params)
    val d = res.get("data")
    return when {
        d == null || d.isJsonNull -> JsonObject()
        d.isJsonObject -> d.asJsonObject
        d.isJsonArray -> JsonObject().apply { add("rows", d.asJsonArray) }
        else -> JsonObject()
    }
}

private fun periodLabel(def: RptDef, f: RptFilters): String {
    if (def.hasMonth) {
        val parts = f.month.split("-")
        return if (parts.size == 2) "${parts[1]}-${parts[0]}" else f.month
    }
    if (!def.hasDate) return ""
    if (def.key == "asof") return "As on ${formatSaleDate(f.to.ifBlank { LocalDate.now().toString() })}"
    return when {
        f.from.isNotBlank() && f.to.isNotBlank() -> "${formatSaleDate(f.from)} to ${formatSaleDate(f.to)}"
        f.from.isNotBlank() -> "From ${formatSaleDate(f.from)}"
        f.to.isNotBlank() -> "Up to ${formatSaleDate(f.to)}"
        else -> ""
    }
}

private fun isHoUser(vm: AppViewModel): Boolean {
    val u = vm.user.value
    return u?.role == "ho_admin" || u?.scope == "ho"
}

// ───────────────────────── Export (CSV for Excel + PDF with letterhead) ─────────────────────────

private class RptLetterhead(
    val businessName: String,
    val address: String,
    val ids: String,
    val branch: String,
    val period: String,
)

private fun letterhead(profile: JsonObject?, branch: String, period: String): RptLetterhead {
    val p = profile ?: JsonObject()
    val addr = listOf(p.str("address"), p.str("city"), p.str("state"), p.str("pincode")).map { it.trim() }.filter { it.isNotEmpty() }.joinToString(", ")
    val ids = buildList {
        p.str("drug_license_no").trim().takeIf { it.isNotEmpty() }?.let { add("DL No.: $it") }
        p.str("gstin").trim().takeIf { it.isNotEmpty() }?.let { add("GSTIN: $it") }
        p.str("fssai_no").trim().takeIf { it.isNotEmpty() }?.let { add("FSSAI: $it") }
        p.str("phone").trim().takeIf { it.isNotEmpty() }?.let { add("Ph: $it") }
        p.str("email").trim().takeIf { it.isNotEmpty() }?.let { add("Email: $it") }
    }.joinToString("  |  ")
    return RptLetterhead(p.str("business_name").trim().ifBlank { "Pharmacy" }, addr, ids, branch, period)
}

private fun safeName(label: String): String =
    label.replace(Regex("\\s+"), "_").replace(Regex("[^a-zA-Z0-9_]"), "") + "_" + LocalDate.now()

private fun excelValue(row: JsonObject, col: RptColumn): Any {
    val raw = rptRaw(row, col.key)
    return when (col.type) {
        "currency", "number" -> raw?.trim()?.toDoubleOrNull() ?: ""
        "date", "expiry" -> rptCell(row, col)
        else -> raw ?: ""
    }
}

/** Same workbook as the web Excel button; saved to Downloads. Returns (uri, fileName). */
private fun downloadReportExcel(
    context: Context,
    def: RptDef,
    head: RptLetterhead,
    cols: List<RptColumn>,
    rows: List<JsonObject>,
): Pair<Uri, String> {
    val letter = buildList {
        add(head.businessName)
        if (head.address.isNotBlank()) add(head.address)
        if (head.ids.isNotBlank()) add(head.ids)
        val meta = buildList {
            if (head.branch.isNotBlank()) add("Branch: ${head.branch}")
            if (head.period.isNotBlank()) add("Period: ${head.period}")
        }
        if (meta.isNotEmpty()) add(meta.joinToString("  |  "))
    }
    val firstNumeric = cols.indexOfFirst { it.type == "currency" || it.type == "number" || it.alignEnd }
    val bytes = ReportExcelWriter.build(
        reportTitle = def.label,
        sheetName = def.label,
        headers = cols.map { it.label },
        rows = rows.map { r -> cols.map { excelValue(r, it) } },
        numericColumnStart = if (firstNumeric >= 0) firstNumeric + 1 else 0,
        footer = if (def.key == "gst") null else rptFooterRaw(cols, rows),
        letterhead = letter,
    )
    val base = def.label.replace(Regex("\\s+"), "_").replace(Regex("[^a-zA-Z0-9_]"), "").ifBlank { def.path }
    val name = "${base}_${LocalDate.now()}.xlsx"
    return ReportExcelWriter.saveToDownloads(context, name, bytes) to name
}

/** Runs [action] after asking for notification permission (Android 13+) so the download notification can show. */
@Composable
internal fun rememberNotifyPermissionGate(): (() -> Unit) -> Unit {
    val context = LocalContext.current
    var pending by remember { mutableStateOf<(() -> Unit)?>(null) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        pending?.invoke()
        pending = null
    }
    return { action ->
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && DownloadNotifier.needsPermission(context)) {
            pending = action
            launcher.launch(Manifest.permission.POST_NOTIFICATIONS)
        } else action()
    }
}

private fun notifyExcel(context: Context, saved: Pair<Uri, String>) =
    DownloadNotifier.notifyDownloaded(context, saved.first, saved.second, ReportExcelWriter.MIME)

internal fun openDownloaded(context: Context, uri: Uri): String? =
    runCatching {
        context.startActivity(
            Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, ReportExcelWriter.MIME)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
            },
        )
    }.exceptionOrNull()?.let { "No app found to open Excel files. The file is saved in Downloads." }

@Composable
internal fun RptDownloadedBar(name: String, onOpen: () -> Unit, onDismiss: () -> Unit) {
    Surface(color = Color(0xFFDCFCE7), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(start = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.CheckCircle, null, Modifier.size(16.dp), tint = Color(0xFF15803D))
            Spacer(Modifier.width(6.dp))
            Text("Saved to Downloads: $name", Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = Color(0xFF14532D), maxLines = 2, overflow = TextOverflow.Ellipsis)
            TextButton(onClick = onOpen) { Text("Open", style = MaterialTheme.typography.labelSmall) }
            TextButton(onClick = onDismiss) { Text("OK", style = MaterialTheme.typography.labelSmall) }
        }
    }
}

/** A4 landscape PDF: letterhead, title, period, table with repeating header, totals row, page numbers. */
private fun writePdfFile(
    context: Context,
    def: RptDef,
    head: RptLetterhead,
    cols: List<RptColumn>,
    rows: List<JsonObject>,
    weights: List<Float>,
    extraLines: List<String>,
): File {
    val pageW = 842
    val pageH = 595
    val margin = 24f
    val usable = pageW - margin * 2
    val serialW = 22f
    val totalWeight = weights.sum().coerceAtLeast(1f)
    val colW = weights.map { it / totalWeight * (usable - serialW) }
    val fontSize = when {
        cols.size > 14 -> 6f
        cols.size > 10 -> 6.6f
        else -> 7.4f
    }
    val rowH = fontSize + 6f

    val text = TextPaint(Paint.ANTI_ALIAS_FLAG).apply { textSize = fontSize; color = android.graphics.Color.rgb(15, 23, 42) }
    val bold = TextPaint(text).apply { typeface = Typeface.create(Typeface.DEFAULT, Typeface.BOLD) }
    val muted = TextPaint(text).apply { color = android.graphics.Color.rgb(100, 116, 139) }
    val title = TextPaint(bold).apply { textSize = 14f }
    val sub = TextPaint(muted).apply { textSize = 8f }
    val headBg = Paint().apply { color = android.graphics.Color.rgb(241, 245, 249) }
    val line = Paint().apply { color = android.graphics.Color.rgb(226, 232, 240); strokeWidth = 0.6f }
    val teal = Paint().apply { color = android.graphics.Color.rgb(13, 148, 136); strokeWidth = 1.2f }

    val doc = PdfDocument()
    var pageNo = 0
    var page: PdfDocument.Page? = null
    var y = 0f
    val footer = if (def.key == "gst") null else rptFooter(cols, rows)
    val generated = LocalDateTime.now().format(DateTimeFormatter.ofPattern("dd-MM-yyyy HH:mm"))

    fun fit(s: String, w: Float, p: TextPaint): String = TextUtils.ellipsize(s, p, w - 4f, TextUtils.TruncateAt.END).toString()

    fun drawRow(cells: List<String>, serial: String, p: TextPaint, bg: Paint?) {
        val c = page!!.canvas
        if (bg != null) c.drawRect(margin, y, margin + usable, y + rowH, bg)
        var x = margin
        c.drawText(fit(serial, serialW, p), x + 2f, y + rowH - 4f, p)
        x += serialW
        cells.forEachIndexed { i, v ->
            val w = colW[i]
            val s = fit(v, w, p)
            if (cols[i].alignEnd) c.drawText(s, x + w - 2f - p.measureText(s), y + rowH - 4f, p)
            else c.drawText(s, x + 2f, y + rowH - 4f, p)
            x += w
        }
        c.drawLine(margin, y + rowH, margin + usable, y + rowH, line)
        y += rowH
    }

    fun finishPage() {
        page?.let {
            val c = it.canvas
            val label = "Page $pageNo  ·  Generated $generated"
            c.drawText(label, pageW - margin - sub.measureText(label), pageH - 12f, sub)
            doc.finishPage(it)
        }
    }

    fun newPage() {
        finishPage()
        pageNo++
        page = doc.startPage(PdfDocument.PageInfo.Builder(pageW, pageH, pageNo).create())
        val c = page!!.canvas
        y = margin
        if (pageNo == 1) {
            val bn = TextPaint(title).apply { textSize = 15f }
            c.drawText(head.businessName, (pageW - bn.measureText(head.businessName)) / 2, y + 12f, bn)
            y += 18f
            if (head.address.isNotBlank()) {
                c.drawText(fit(head.address, usable, sub), (pageW - sub.measureText(head.address).coerceAtMost(usable)) / 2, y + 8f, sub)
                y += 11f
            }
            if (head.ids.isNotBlank()) {
                c.drawText(fit(head.ids, usable, sub), (pageW - sub.measureText(head.ids).coerceAtMost(usable)) / 2, y + 8f, sub)
                y += 11f
            }
            c.drawLine(margin, y + 4f, margin + usable, y + 4f, teal)
            y += 10f
            c.drawText(def.label, margin, y + 12f, title)
            val meta = listOf(head.period, head.branch.takeIf { it.isNotBlank() }?.let { "Branch: $it" } ?: "")
                .filter { it.isNotBlank() }.joinToString("   |   ")
            if (meta.isNotBlank()) c.drawText(meta, margin + usable - sub.measureText(meta), y + 12f, sub)
            y += 20f
            extraLines.forEach { l ->
                c.drawText(fit(l, usable, bold), margin, y + 9f, bold)
                y += 12f
            }
            if (extraLines.isNotEmpty()) y += 4f
        }
        drawRow(cols.map { it.label }, "#", bold, headBg)
    }

    newPage()
    if (rows.isEmpty()) {
        page!!.canvas.drawText("No records for the selected filters.", margin, y + 14f, muted)
    }
    rows.forEachIndexed { i, r ->
        if (y + rowH > pageH - 30f) newPage()
        drawRow(cols.map { rptCell(r, it) }, (i + 1).toString(), text, null)
    }
    footer?.let {
        if (y + rowH > pageH - 30f) newPage()
        drawRow(it, "", bold, headBg)
    }
    finishPage()
    val file = File(context.cacheDir, "${safeName(def.label)}.pdf")
    file.outputStream().use { doc.writeTo(it) }
    doc.close()
    return file
}

private fun colWidthDp(col: RptColumn, rows: List<JsonObject>): Float {
    val sample = rows.asSequence().take(150).map { rptCell(it, col).length }.maxOrNull() ?: 0
    val est = max(sample, col.label.length) * 6.4f + 12f
    val base = when (col.type) {
        "currency" -> 88f
        "number" -> 64f
        "date" -> 76f
        "expiry" -> 60f
        "badge" -> 84f
        else -> 0f
    }
    return if (base > 0f) max(base, est.coerceAtMost(150f)) else est.coerceIn(64f, 230f)
}

private var rptProfileCache: JsonObject? = null

private fun loadProfile(vm: AppViewModel): JsonObject? =
    rptProfileCache ?: runCatching { vm.api.get("profile").obj("data") }.getOrNull()?.also { rptProfileCache = it }

// ───────────────────────── Hub ─────────────────────────

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ReportsScreen(vm: AppViewModel, onBack: () -> Unit, onOpenReport: (String) -> Unit) {
    val context = LocalContext.current
    val cfg = remember { loadReportConfig(context) }
    var search by rememberSaveable { mutableStateOf("") }
    var category by rememberSaveable { mutableStateOf("all") }
    var busyKey by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val wide = LocalConfiguration.current.screenWidthDp >= 600

    fun inCategory(r: RptDef, cat: String) = when (cat) {
        "all" -> true
        "gst" -> r.category == "gst" || r.label.contains("gst", ignoreCase = true)
        else -> r.category == cat
    }
    val filtered = remember(search, category) {
        val q = search.trim().lowercase()
        cfg.reports.filter { inCategory(it, category) && (q.isEmpty() || it.label.lowercase().contains(q)) }
    }
    val catColor = cfg.categories.firstOrNull { it.id == category }?.color ?: PharmaTeal

    var downloaded by remember { mutableStateOf<Pair<Uri, String>?>(null) }
    val notifyGate = rememberNotifyPermissionGate()

    fun exportFromHub(def: RptDef) = notifyGate {
        busyKey = def.key
        scope.launch {
            val result = runCatching {
                withContext(Dispatchers.IO) {
                    val data = fetchReport(vm, def, rptParams(def, RptFilters(), false))
                    val rows = rptRows(def, data)
                    val cols = rptColumns(cfg, def, "", data)
                    val head = letterhead(loadProfile(vm), "", periodLabel(def, RptFilters()))
                    downloadReportExcel(context, def, head, cols, rows)
                }
            }
            busyKey = null
            result.onSuccess { downloaded = it; notifyExcel(context, it) }
                .onFailure { onOpenReport(def.path) }
        }
    }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        PoHeader("Reports", "${cfg.reports.size} reports · GST, purchase, sales, stock and accounts", onBack)
        if (!vm.canView("ALL_REPORTS")) {
            PharmaCard {
                Text("You don't have access to Reports.", style = MaterialTheme.typography.bodyMedium, color = PharmaSlate)
                Text("Ask your admin to enable ‘All Reports’ in User Rights.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
            return@Column
        }
        SalesDenseSearchField(
            value = search,
            onValueChange = { search = it },
            placeholder = "Search reports…",
            modifier = Modifier.fillMaxWidth(),
            leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            trailingIcon = if (search.isNotEmpty()) ({
                Icon(Icons.Default.Close, "Clear", Modifier.size(16.dp).clickable { search = "" }, tint = PharmaMuted)
            }) else null,
        )
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            items(cfg.categories) { c ->
                val count = cfg.reports.count { inCategory(it, c.id) }
                val sel = c.id == category
                Surface(
                    shape = RoundedCornerShape(16.dp),
                    color = if (sel) c.color else Color.White,
                    border = BorderStroke(1.dp, if (sel) c.color else PharmaBorder),
                    modifier = Modifier.clip(RoundedCornerShape(16.dp)).clickable { category = c.id },
                ) {
                    Row(Modifier.padding(horizontal = 10.dp, vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(c.name, style = MaterialTheme.typography.labelMedium, color = if (sel) Color.White else PharmaSlate)
                        Spacer(Modifier.width(5.dp))
                        Text(
                            "$count",
                            style = MaterialTheme.typography.labelSmall,
                            color = if (sel) Color.White else c.color,
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }
            }
        }
        error?.let { ErrorBanner(it) { error = null } }
        downloaded?.let { (uri, name) ->
            RptDownloadedBar(name, onOpen = { error = openDownloaded(context, uri) }, onDismiss = { downloaded = null })
        }
        if (filtered.isEmpty()) {
            PharmaCard { Text("No reports match “$search”.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted) }
        }
        LazyVerticalGrid(
            columns = GridCells.Adaptive(if (wide) 300.dp else 260.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier.weight(1f),
        ) {
            items(filtered, key = { it.key }) { r ->
                val color = cfg.categories.firstOrNull { it.id == r.category }?.color ?: catColor
                ReportCatalogCard(
                    def = r,
                    color = color,
                    categoryName = r.group.ifBlank { r.category.replaceFirstChar { it.uppercase() } },
                    busy = busyKey == r.key,
                    onView = { onOpenReport(r.path) },
                    onExcel = { exportFromHub(r) },
                )
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ReportCatalogCard(
    def: RptDef,
    color: Color,
    categoryName: String,
    busy: Boolean,
    onView: () -> Unit,
    onExcel: () -> Unit,
) {
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color.White,
        border = BorderStroke(1.dp, PharmaBorder),
        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable(onClick = onView),
    ) {
        Row(Modifier.height(IntrinsicSize.Min)) {
            Box(Modifier.width(4.dp).fillMaxHeight().background(color))
            Column(Modifier.padding(10.dp).weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.Assessment, null, Modifier.size(18.dp), tint = color)
                    Spacer(Modifier.width(6.dp))
                    Text(
                        def.label,
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = PharmaSlate,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f),
                    )
                }
                FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    RptTag(categoryName, color)
                    when {
                        def.hasMonth -> RptTag("Month", PharmaMuted)
                        def.key == "asof" -> RptTag("As on date", PharmaMuted)
                        def.hasDate -> RptTag("Date range", PharmaMuted)
                        else -> RptTag("Current", PharmaMuted)
                    }
                    if (def.hasMonths) RptTag("Months window", PharmaMuted)
                    if (def.hasMargin) RptTag("Margin %", PharmaMuted)
                    def.entity?.let { RptTag("By ${it.label}", PharmaMuted) }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    PharmaPrimaryButton("View", Modifier.weight(1f), onClick = onView)
                    PharmaOutlinedButton(if (busy) "Preparing…" else "Excel", Modifier.weight(1f), enabled = !busy, onClick = onExcel)
                }
            }
        }
    }
}

@Composable
private fun RptTag(text: String, color: Color) {
    Text(
        text,
        style = MaterialTheme.typography.labelSmall,
        color = color,
        modifier = Modifier
            .clip(RoundedCornerShape(10.dp))
            .background(color.copy(alpha = 0.10f))
            .padding(horizontal = 7.dp, vertical = 2.dp),
    )
}

// ───────────────────────── Runner ─────────────────────────

@OptIn(ExperimentalLayoutApi::class, ExperimentalMaterial3Api::class)
@Composable
fun ReportRunnerScreen(vm: AppViewModel, reportPath: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val cfg = remember { loadReportConfig(context) }
    val def = remember(reportPath) { cfg.find(reportPath) }
    if (def == null) {
        Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            PoHeader("Report", reportPath, onBack)
            ErrorBanner("This report is not available on mobile.")
        }
        return
    }
    val allowed = vm.canView("ALL_REPORTS") || (def.key == "stockAdjAudit" && vm.canView("OPENING_STOCK"))
    val isHo = remember { isHoUser(vm) }
    val wide = LocalConfiguration.current.screenWidthDp >= 600
    val scope = rememberCoroutineScope()

    var filters by remember { mutableStateOf(RptFilters()) }
    var marginText by remember { mutableStateOf("") }
    var branches by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var entityOptions by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var entityLoading by remember { mutableStateOf(false) }
    var entityPicker by remember { mutableStateOf(false) }
    var data by remember { mutableStateOf<JsonObject?>(null) }
    var loading by remember { mutableStateOf(false) }
    var exporting by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var resultsSearch by remember { mutableStateOf("") }
    var filtersOpen by remember { mutableStateOf(wide) }
    var detailRow by remember { mutableStateOf<JsonObject?>(null) }
    var ranFilters by remember { mutableStateOf(filters) }
    var downloaded by remember { mutableStateOf<Pair<Uri, String>?>(null) }
    val notifyGate = rememberNotifyPermissionGate()

    fun branchLabel(): String {
        if (!isHo) return ""
        val id = ranFilters.branchId ?: return "All Branches"
        val b = branches.firstOrNull { it.jInt("id") == id } ?: return "Branch #$id"
        return "${b.str("code")} - ${b.str("name")}"
    }

    fun run() {
        if (!allowed) return
        loading = true
        error = null
        resultsSearch = ""
        val snapshot = filters
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { fetchReport(vm, def, rptParams(def, snapshot, isHo)) } }
                .onSuccess { data = it; ranFilters = snapshot }
                .onFailure { data = null; error = it.message ?: "Could not load report." }
            loading = false
        }
    }

    fun loadEntityOptions(thenRun: Boolean) {
        val ef = def.entity ?: return
        entityLoading = true
        scope.launch {
            val q = mutableMapOf("source" to ef.source)
            if (isHo && filters.branchId != null) q["branch_id"] = filters.branchId.toString()
            val list = runCatching { withContext(Dispatchers.IO) { vm.api.get("reports/filter-options", q).dataRows() } }
                .getOrElse { emptyList() }
            entityOptions = list
            entityLoading = false
            if (!ef.optional && list.isNotEmpty() && filters.entityValue.isBlank()) {
                filters = filters.copy(entityValue = list.first().str("value"))
            } else if (filters.entityValue.isNotBlank() && list.none { it.str("value") == filters.entityValue }) {
                filters = filters.copy(entityValue = "")
            }
            if (thenRun) run()
        }
    }

    LaunchedEffect(def.key) {
        if (!allowed) return@LaunchedEffect
        if (isHo) {
            branches = runCatching { withContext(Dispatchers.IO) { vm.api.get("reports/branches").dataRows() } }.getOrElse { emptyList() }
        }
        if (def.entity != null) loadEntityOptions(true) else run()
    }

    val columns = remember(data, ranFilters) { rptColumns(cfg, def, ranFilters.entityValue, data) }
    val allRows = remember(data) { rptRows(def, data) }
    val shownRows = remember(allRows, resultsSearch) {
        val q = resultsSearch.trim().lowercase()
        if (q.isEmpty()) allRows
        else allRows.filter { r -> r.entrySet().any { (_, v) -> !v.isJsonNull && (if (v.isJsonPrimitive) v.asString else v.toString()).lowercase().contains(q) } }
    }
    val widths = remember(columns, allRows) { columns.map { colWidthDp(it, allRows) } }
    val footer = remember(columns, allRows, resultsSearch) {
        if (def.key == "gst" || resultsSearch.isNotBlank()) null else rptFooter(columns, allRows)
    }

    fun extraLines(d: JsonObject?): List<String> {
        if (d == null) return emptyList()
        val out = mutableListOf<String>()
        if (def.key == "profit") out += "Total Profit: ₹${rptMoney(d.amount("total_profit"))}"
        if (def.key == "stock" && d.has("total_value")) out += "Items: ${d.str("total_items")}   Stock Value: ₹${rptMoney(d.amount("total_value"))}"
        val t = d.obj("totals")
        if (t.size() > 0) {
            val bits = mutableListOf("Records: ${t.str("count")}")
            if (t.has("net") && !t.get("net").isJsonNull) bits += "Net: ${rptMoney(t.amount("net"))}"
            if (t.has("gst") && !t.get("gst").isJsonNull) bits += "GST: ${rptMoney(t.amount("gst"))}"
            if (t.has("profit") && !t.get("profit").isJsonNull) bits += "Profit: ${rptMoney(t.amount("profit"))}"
            out += bits.joinToString("   ")
        }
        return out
    }

    fun export(pdf: Boolean) {
        val d = data ?: return
        exporting = true
        scope.launch {
            val result = runCatching {
                withContext(Dispatchers.IO) {
                    val head = letterhead(loadProfile(vm), branchLabel(), periodLabel(def, ranFilters))
                    if (pdf) writePdfFile(context, def, head, columns, allRows, widths, extraLines(d))
                    else downloadReportExcel(context, def, head, columns, allRows)
                }
            }
            exporting = false
            result.onSuccess { out ->
                @Suppress("UNCHECKED_CAST")
                if (pdf) {
                    val file = out as File
                    PdfWifiPrint.printPdfFile(
                        context,
                        file,
                        def.label,
                        PdfWifiPrint.Orientation.Landscape,
                    )?.let { error = it }
                } else {
                    val saved = out as Pair<Uri, String>
                    downloaded = saved
                    notifyExcel(context, saved)
                }
            }.onFailure { error = "Export failed: ${it.message}" }
        }
    }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PoHeader(def.label, listOf(periodLabel(def, ranFilters), branchLabel()).filter { it.isNotBlank() }.joinToString(" · ").ifBlank { "Report" }, onBack) {
            val canExport = data != null && !loading && !exporting
            IconButton(onClick = { run() }, enabled = !loading && allowed) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
            IconButton(onClick = { notifyGate { export(false) } }, enabled = canExport) { Icon(Icons.Default.TableChart, "Excel", tint = if (canExport) Color(0xFF15803D) else PharmaMuted) }
            IconButton(onClick = { export(true) }, enabled = canExport) {
                Icon(Icons.Default.Print, "Print PDF (Wi‑Fi)", tint = if (canExport) Color(0xFFB91C1C) else PharmaMuted)
            }
        }
        if (!allowed) {
            ErrorBanner("You don't have access to this report.")
            return@Column
        }

        // Filters
        Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
            Column(Modifier.fillMaxWidth().padding(8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(Modifier.fillMaxWidth().clickable { filtersOpen = !filtersOpen }, verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.FilterList, null, Modifier.size(16.dp), tint = PharmaTeal)
                    Spacer(Modifier.width(6.dp))
                    Text("Filters", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    Spacer(Modifier.width(8.dp))
                    val summary = buildList {
                        periodLabel(def, filters).takeIf { it.isNotBlank() }?.let { add(it) }
                        if (def.hasMonths) add("${filters.months} months")
                        if (def.hasMargin && filters.marginPct != 0.0) add("Margin ${marginText}%")
                        def.entity?.let { ef ->
                            val sel = entityOptions.firstOrNull { it.str("value") == filters.entityValue }
                            add(sel?.str("label") ?: if (filters.entityValue.isBlank()) "All ${ef.label}" else filters.entityValue)
                        }
                    }.joinToString(" · ")
                    Text(summary, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                    Icon(if (filtersOpen) Icons.Default.ExpandLess else Icons.Default.ExpandMore, null, tint = PharmaMuted)
                }
                if (filtersOpen) {
                    val fieldW = if (wide) 180.dp else 150.dp
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        if (isHo) {
                            val ids = listOf("") + branches.map { it.jInt("id").toString() }
                            POptionDropdown(
                                label = "Branch",
                                value = filters.branchId?.toString() ?: "",
                                options = ids,
                                onSelect = { v ->
                                    filters = filters.copy(branchId = v.toIntOrNull(), entityValue = "")
                                    if (def.entity != null) loadEntityOptions(false)
                                },
                                modifier = Modifier.width(fieldW),
                                display = { v ->
                                    if (v.isBlank()) "All Branches"
                                    else branches.firstOrNull { it.jInt("id").toString() == v }?.let { "${it.str("code")} - ${it.str("name")}" } ?: v
                                },
                            )
                        }
                        val today = LocalDate.now().toString()
                        when {
                            def.hasMonth -> {
                                val months = (0 until 36).map { YearMonth.now().minusMonths(it.toLong()).toString() }
                                POptionDropdown(
                                    label = "Month",
                                    value = filters.month,
                                    options = months,
                                    onSelect = { filters = filters.copy(month = it) },
                                    modifier = Modifier.width(fieldW),
                                    display = { v ->
                                        runCatching { YearMonth.parse(v).format(DateTimeFormatter.ofPattern("MMM yyyy", Locale.ENGLISH)) }.getOrDefault(v)
                                    },
                                )
                            }
                            def.key == "asof" -> PDateField("As on date", filters.to, { v ->
                                filters = filters.copy(to = minOf(v, today))
                            }, Modifier.width(fieldW))
                            def.hasDate -> {
                                PDateField("From", filters.from, { v ->
                                    val from = minOf(v, today)
                                    filters = filters.copy(from = from, to = if (filters.to.isNotBlank() && from > filters.to) from else filters.to)
                                }, Modifier.width(fieldW))
                                PDateField("To", filters.to, { v ->
                                    var to = minOf(v, today)
                                    if (filters.from.isNotBlank() && to < filters.from) to = filters.from
                                    filters = filters.copy(to = to)
                                }, Modifier.width(fieldW))
                            }
                        }
                        if (def.hasMonths) {
                            POptionDropdown(
                                label = "Within months",
                                value = filters.months.toString(),
                                options = listOf("3", "6", "9", "12"),
                                onSelect = { filters = filters.copy(months = it.toIntOrNull() ?: 3) },
                                modifier = Modifier.width(fieldW),
                                display = { "$it months" },
                            )
                        }
                        if (def.hasMargin) {
                            SalesFormField(
                                label = "Margin %",
                                value = marginText,
                                onValueChange = { t ->
                                    val clean = t.filter { it.isDigit() || it == '.' }
                                    marginText = clean
                                    filters = filters.copy(marginPct = clean.toDoubleOrNull() ?: 0.0)
                                },
                                modifier = Modifier.width(110.dp),
                                placeholder = "0",
                                keyboardType = androidx.compose.ui.text.input.KeyboardType.Decimal,
                            )
                        }
                        def.entity?.let { ef ->
                            val sel = entityOptions.firstOrNull { it.str("value") == filters.entityValue }
                            Column(Modifier.width(if (wide) 280.dp else 306.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(ef.label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                    if (!entityLoading && entityOptions.isNotEmpty()) {
                                        Text("  ${entityOptions.size} available", style = MaterialTheme.typography.labelSmall, color = PharmaMuted.copy(alpha = 0.7f))
                                    }
                                    if (sel != null && isOpeningOpt(sel)) {
                                        Spacer(Modifier.width(6.dp))
                                        RptTag("Opening Stock", Color(0xFF0369A1))
                                    }
                                }
                                Surface(
                                    shape = RoundedCornerShape(6.dp),
                                    color = Color.White,
                                    border = BorderStroke(1.dp, PharmaBorder),
                                    modifier = Modifier.fillMaxWidth().height(SalesDenseFieldHeight).clip(RoundedCornerShape(6.dp))
                                        .clickable(enabled = !entityLoading) { entityPicker = true },
                                ) {
                                    Row(Modifier.padding(horizontal = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Icon(Icons.Default.Search, null, Modifier.size(14.dp), tint = PharmaMuted)
                                        Spacer(Modifier.width(6.dp))
                                        Text(
                                            when {
                                                entityLoading -> "Loading ${ef.label.lowercase()}s…"
                                                sel != null -> sel.str("label")
                                                filters.entityValue.isNotBlank() -> filters.entityValue
                                                ef.optional -> "All ${ef.label}s (summary)"
                                                else -> "Select ${ef.label.lowercase()}"
                                            },
                                            style = MaterialTheme.typography.labelMedium,
                                            color = if (sel != null) PharmaSlate else PharmaMuted,
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis,
                                            modifier = Modifier.weight(1f),
                                        )
                                        if (filters.entityValue.isNotBlank() && ef.optional) {
                                            Icon(Icons.Default.Close, "Clear", Modifier.size(16.dp).clickable {
                                                filters = filters.copy(entityValue = "")
                                                run()
                                            }, tint = PharmaMuted)
                                        }
                                    }
                                }
                            }
                        }
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PharmaPrimaryButton(if (loading) "Loading…" else "Run report", enabled = !loading) {
                            run()
                            if (!wide) filtersOpen = false
                        }
                        PharmaOutlinedButton("Reset") {
                            filters = RptFilters(branchId = filters.branchId)
                            marginText = ""
                            if (def.entity != null) loadEntityOptions(true) else run()
                        }
                    }
                }
            }
        }

        error?.let { ErrorBanner(it) { error = null } }
        downloaded?.let { (uri, name) ->
            RptDownloadedBar(name, onOpen = { error = openDownloaded(context, uri) }, onDismiss = { downloaded = null })
        }

        if (loading) {
            Box(Modifier.weight(1f)) { LoadingBlock("Loading report…") }
            return@Column
        }
        val d = data
        if (d == null) {
            Box(Modifier.weight(1f).fillMaxWidth(), contentAlignment = Alignment.Center) {
                Text("Choose filters and tap Run report.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
            }
            return@Column
        }

        // Summary / special layouts
        if (def.key == "gst") {
            val net = d.amount("net_payable")
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                RptKpi("Output GST (Sales)", "₹${rptMoney(d.amount("output_gst"))}", PharmaSlate, Modifier.widthIn(min = 150.dp))
                RptKpi("Input GST (Purchase)", "₹${rptMoney(d.amount("input_gst"))}", PharmaSlate, Modifier.widthIn(min = 150.dp))
                RptKpi("Net Payable", "₹${rptMoney(net)}", if (net > 0) Color(0xFFB91C1C) else Color(0xFF15803D), Modifier.widthIn(min = 150.dp))
            }
            Text(
                if (net > 0) "GST payable to government for the period." else "Input credit covers output GST for the period.",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
            return@Column
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (def.key == "profit") {
                SalesLogKpiChip("Total Profit", "₹${rptMoney(d.amount("total_profit"))}")
            }
            val t = d.obj("totals")
            if (t.size() > 0) {
                SalesLogKpiChip("Records", t.str("count").ifBlank { allRows.size.toString() })
                if (t.has("net") && !t.get("net").isJsonNull) SalesLogKpiChip("Net", rptMoney(t.amount("net")))
                if (t.has("gst") && !t.get("gst").isJsonNull) SalesLogKpiChip("GST", rptMoney(t.amount("gst")))
                if (t.has("profit") && !t.get("profit").isJsonNull) SalesLogKpiChip("Profit", rptMoney(t.amount("profit")))
            } else {
                SalesLogKpiChip("Records", allRows.size.toString())
            }
            if (def.key == "stock" && d.has("total_value") && !d.get("total_value").isJsonNull) {
                SalesLogKpiChip("Items", d.str("total_items"))
                SalesLogKpiChip("Stock Value", "₹${rptMoney(d.amount("total_value"))}")
            }
        }
        if (def.key == "profit") {
            Text("Top products by profit", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
        }

        if (allRows.isNotEmpty()) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SalesDenseSearchField(
                    value = resultsSearch,
                    onValueChange = { resultsSearch = it },
                    placeholder = "Search in results…",
                    modifier = Modifier.weight(1f),
                    leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
                    trailingIcon = if (resultsSearch.isNotEmpty()) ({
                        Icon(Icons.Default.Close, "Clear", Modifier.size(16.dp).clickable { resultsSearch = "" }, tint = PharmaMuted)
                    }) else null,
                )
                Spacer(Modifier.width(8.dp))
                Text(
                    if (resultsSearch.isBlank()) "${allRows.size} rows" else "${shownRows.size} of ${allRows.size}",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
            }
        }

        if (columns.isEmpty() || allRows.isEmpty()) {
            Box(Modifier.weight(1f).fillMaxWidth(), contentAlignment = Alignment.Center) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Default.Inbox, null, Modifier.size(32.dp), tint = PharmaMuted)
                    Text("No records for the selected filters.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                }
            }
        } else if (shownRows.isEmpty()) {
            Box(Modifier.weight(1f).fillMaxWidth(), contentAlignment = Alignment.Center) {
                Text("No rows match “$resultsSearch”.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
            }
        } else {
            RptTable(columns, shownRows, widths.map { it.dp }, footer, Modifier.weight(1f)) { detailRow = it }
            Text("Tap a row to see all fields · swipe sideways for more columns", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        }
    }

    if (entityPicker && def.entity != null) {
        RptEntityPicker(
            entity = def.entity,
            options = entityOptions,
            selected = filters.entityValue,
            onDismiss = { entityPicker = false },
            onPick = { v ->
                entityPicker = false
                filters = filters.copy(entityValue = v)
                run()
            },
        )
    }

    detailRow?.let { row ->
        ModalBottomSheet(onDismissRequest = { detailRow = null }, containerColor = Color.White) {
            Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(bottom = 24.dp)) {
                Text(def.label, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                Spacer(Modifier.height(8.dp))
                LazyColumn {
                    items(columns) { c ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
                            Text(c.label, Modifier.weight(0.42f), style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                            val v = rptCell(row, c)
                            if (c.type == "badge" && v != "-") {
                                Box(Modifier.weight(0.58f)) { RptBadge(v) }
                            } else {
                                Text(v, Modifier.weight(0.58f), style = MaterialTheme.typography.labelMedium, color = PharmaSlate, fontWeight = if (c.alignEnd) FontWeight.SemiBold else FontWeight.Normal)
                            }
                        }
                        HorizontalDivider(color = PharmaBorder)
                    }
                }
            }
        }
    }
}

private fun isOpeningOpt(o: JsonObject): Boolean =
    o.bool("from_opening") || Regex("\\(Opening Stock\\)", RegexOption.IGNORE_CASE).containsMatchIn(o.str("label"))

@Composable
private fun RptKpi(label: String, value: String, valueColor: Color, modifier: Modifier = Modifier) {
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = modifier) {
        Column(Modifier.padding(horizontal = 14.dp, vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            Text(value, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = valueColor)
        }
    }
}

@Composable
private fun RptBadge(v: String) {
    val (bg, fg) = badgeColors(v)
    Text(
        v,
        style = MaterialTheme.typography.labelSmall,
        color = fg,
        maxLines = 1,
        overflow = TextOverflow.Ellipsis,
        modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(bg).padding(horizontal = 6.dp, vertical = 1.dp),
    )
}

@Composable
private fun RptTable(
    cols: List<RptColumn>,
    rows: List<JsonObject>,
    widths: List<Dp>,
    footer: List<String>?,
    modifier: Modifier = Modifier,
    onRowClick: (JsonObject) -> Unit,
) {
    val serialW = 32.dp
    val total = widths.fold(serialW) { a, b -> a + b }
    val h = rememberScrollState()
    Box(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .background(Color.White),
    ) {
        Column(Modifier.fillMaxHeight().horizontalScroll(h)) {
            Row(Modifier.width(total).background(BillGridHeaderBg)) {
                BillGridTh("#", serialW)
                cols.forEachIndexed { i, c -> BillGridTh(c.label, widths[i], c.alignEnd) }
            }
            LazyColumn(Modifier.width(total).weight(1f)) {
                itemsIndexed(rows) { idx, row ->
                    Row(
                        Modifier
                            .width(total)
                            .background(if (idx % 2 == 1) Color(0xFFF8FAFC) else Color.White)
                            .clickable { onRowClick(row) },
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        BillGridCellText("${idx + 1}", serialW, color = PharmaMuted)
                        cols.forEachIndexed { i, c ->
                            val v = rptCell(row, c)
                            if (c.type == "badge" && v != "-") {
                                Box(Modifier.width(widths[i]).padding(horizontal = 2.dp, vertical = 3.dp)) { RptBadge(v) }
                            } else {
                                BillGridCellText(v, widths[i], c.alignEnd, color = PharmaSlate)
                            }
                        }
                    }
                    HorizontalDivider(Modifier.width(total), color = PharmaBorder)
                }
            }
            footer?.let { f ->
                Row(Modifier.width(total).background(Color(0xFFE6FFFA))) {
                    BillGridCellText("", serialW)
                    cols.forEachIndexed { i, c ->
                        BillGridCellText(f[i], widths[i], c.alignEnd, color = PharmaTealDark, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
    }
}

@Composable
private fun RptEntityPicker(
    entity: RptEntity,
    options: List<JsonObject>,
    selected: String,
    onDismiss: () -> Unit,
    onPick: (String) -> Unit,
) {
    var q by remember { mutableStateOf("") }
    val list = remember(q, options) {
        val t = q.trim().lowercase()
        if (t.isEmpty()) options else options.filter { it.str("label").lowercase().contains(t) || it.str("value").lowercase().contains(t) }
    }
    Dialog(onDismissRequest = onDismiss) {
        Surface(shape = RoundedCornerShape(12.dp), color = Color.White, modifier = Modifier.fillMaxWidth().heightIn(max = 560.dp)) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Select ${entity.label}", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, modifier = Modifier.weight(1f))
                    Text("${options.size}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
                SalesDenseSearchField(
                    value = q,
                    onValueChange = { q = it },
                    placeholder = "Search ${entity.label.lowercase()}…",
                    modifier = Modifier.fillMaxWidth(),
                    leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
                )
                LazyColumn(Modifier.weight(1f, fill = false)) {
                    if (entity.optional && q.isBlank()) {
                        item {
                            RptPickRow("All ${entity.label}s (summary)", selected.isBlank(), false) { onPick("") }
                        }
                    }
                    items(list.take(500), key = { it.str("value") }) { o ->
                        RptPickRow(o.str("label").ifBlank { o.str("value") }, o.str("value") == selected, isOpeningOpt(o)) { onPick(o.str("value")) }
                    }
                    if (list.isEmpty()) {
                        item { Text("No matches.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted, modifier = Modifier.padding(8.dp)) }
                    } else if (list.size > 500) {
                        item { Text("Showing first 500 — refine the search.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted, modifier = Modifier.padding(8.dp)) }
                    }
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                    TextButton(onClick = onDismiss) { Text("Close") }
                }
            }
        }
    }
}

@Composable
private fun RptPickRow(label: String, selected: Boolean, opening: Boolean, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .background(if (selected) PharmaTeal.copy(alpha = 0.10f) else Color.Transparent)
            .clickable(onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = PharmaSlate, modifier = Modifier.weight(1f), maxLines = 2, overflow = TextOverflow.Ellipsis)
        if (opening) RptTag("OS", Color(0xFF0369A1))
        if (selected) Icon(Icons.Default.Check, null, Modifier.size(16.dp).padding(start = 4.dp), tint = PharmaTeal)
    }
}
