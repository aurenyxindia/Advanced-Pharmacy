package `in`.paperlesspharmacy.advanced.ui.screens

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.PictureAsPdf
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.transaction.PurchaseOrderRepository
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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.util.Locale
import java.util.concurrent.atomic.AtomicLong
import kotlin.math.floor

// ───────────────────────── Models ─────────────────────────

private enum class PoView { List, Customise, Auto }

private data class PoDist(val id: Int, val name: String)

private val poLineKeys = AtomicLong(1)

private data class PoLine(
    val key: Long = poLineKeys.getAndIncrement(),
    val bindingId: Int? = null,
    val masterId: Int,
    val name: String,
    val pack: String = "",
    val category: String = "",
    val dosage: String = "",
    val mrp: Double = 0.0,
    val hsn: String = "",
    val manufacturer: String = "",
    val generic: String = "",
    val qty: Double = 0.0,
)

private data class SbRow(
    val bindingId: Int,
    val masterId: Int,
    val name: String,
    val pack: String,
    val category: String,
    val mrp: Double,
    val hsn: String,
    val manufacturer: String,
    val available: Double,
    val soldByMonth: Map<String, Double>,
    val totalSold: Double,
    val distId: Int?,
    val distName: String,
    val checked: Boolean = false,
    val reqQty: Double = 0.0,
)

private data class AutoGroup(
    val key: String,
    val distId: Int?,
    val distName: String,
    /** Last distributor name from the short book (shown when it is not in the Distributor Master). */
    val hint: String,
    val products: List<SbRow>,
    val saving: Boolean = false,
    val error: String? = null,
)

private class PoListFilters {
    var searchDraft by mutableStateOf("")
    var fromDate by mutableStateOf("")
    var toDate by mutableStateOf(LocalDate.now().toString())
    var poType by mutableStateOf("")
}

private class PoAutoState {
    var loading by mutableStateOf(false)
    var error by mutableStateOf<String?>(null)
    var months by mutableStateOf<List<String>>(emptyList())
    var rows by mutableStateOf<List<SbRow>>(emptyList())
    var filter by mutableStateOf("")
    var reqDays by mutableIntStateOf(0)
    var groups by mutableStateOf<List<AutoGroup>?>(null)
}

private val PoAmberBg = Color(0xFFFEF3C7)
private val PoAmberText = Color(0xFF92400E)
private val PoBlueBg = Color(0xFFDBEAFE)
private val PoBlueText = Color(0xFF1E40AF)
private val PoFactsBg = Color(0xFFF0FDFA)

// ───────────────────────── Helpers ─────────────────────────

private fun poQty(v: Double): String = pNumText(v, false).ifBlank { "0" }

private fun poR2(v: Double): Double = floor(v * 100.0 + 0.5) / 100.0

private fun poMonthLabel(ym: String): String =
    runCatching { YearMonth.parse(ym).format(DateTimeFormatter.ofPattern("MMM yy", Locale.ENGLISH)) }.getOrDefault(ym)

private fun poErr(t: Throwable, fallback: String): String = t.message?.takeIf { it.isNotBlank() } ?: fallback

private fun poLineFromJson(o: JsonObject): PoLine = PoLine(
    bindingId = o.optInt("product_binding_id")?.takeIf { it > 0 },
    masterId = o.jInt("master_product_id"),
    name = o.str("product_name"),
    pack = o.str("pack_size"),
    category = o.str("product_category"),
    dosage = o.str("dosage_form"),
    mrp = o.amount("mrp"),
    hsn = o.str("hsn_code"),
    manufacturer = o.str("manufacturer"),
    generic = o.str("generic_name"),
    qty = o.amount("qty"),
)

private fun poLineToJson(l: PoLine): JsonObject = JsonObject().apply {
    l.bindingId?.let { addProperty("product_binding_id", it) }
    addProperty("master_product_id", l.masterId)
    addProperty("product_name", l.name)
    addProperty("pack_size", l.pack)
    addProperty("product_category", l.category)
    addProperty("dosage_form", l.dosage)
    addProperty("mrp", l.mrp)
    addProperty("hsn_code", l.hsn)
    addProperty("manufacturer", l.manufacturer)
    addProperty("generic_name", l.generic)
    addProperty("qty", l.qty)
}

private fun poSbFromJson(p: JsonObject): SbRow {
    val sold = mutableMapOf<String, Double>()
    p.get("sold_by_month")?.takeIf { it.isJsonObject }?.asJsonObject?.entrySet()?.forEach { (k, v) ->
        sold[k] = runCatching { v.asDouble }.getOrDefault(0.0)
    }
    return SbRow(
        bindingId = p.jInt("product_binding_id"),
        masterId = p.jInt("master_product_id"),
        name = p.str("product_name"),
        pack = p.str("pack_size"),
        category = p.str("product_category"),
        mrp = p.amount("mrp"),
        hsn = p.str("hsn_code"),
        manufacturer = p.str("manufacturer"),
        available = p.amount("available_qty"),
        soldByMonth = sold,
        totalSold = p.amount("total_sold_qty"),
        distId = p.optInt("distributor_id")?.takeIf { it > 0 },
        distName = p.str("distributor_name").trim(),
    )
}

private fun poBuildGroups(selected: List<SbRow>): List<AutoGroup> {
    val map = LinkedHashMap<String, AutoGroup>()
    for (r in selected) {
        val name = r.distName.ifBlank { "Unknown" }
        val key = if (r.distId != null) "id:${r.distId}" else "name:${name.lowercase()}"
        val g = map[key]
        map[key] = if (g == null) {
            val known = if (name == "Unknown") "" else name
            AutoGroup(key, r.distId, known, known, listOf(r))
        } else {
            g.copy(products = g.products + r)
        }
    }
    return map.values.toList()
}

private fun poResolveGroup(g: AutoGroup, dists: List<PoDist>): AutoGroup {
    if (g.distId != null) {
        return dists.firstOrNull { it.id == g.distId }?.let { g.copy(distName = it.name) } ?: g
    }
    val key = g.distName.trim()
    if (key.isBlank()) return g
    return dists.firstOrNull { it.name.trim().equals(key, ignoreCase = true) }
        ?.let { g.copy(distId = it.id, distName = it.name) } ?: g
}

private fun poPdfIntent(context: Context, bytes: ByteArray, poNo: String, share: Boolean): String? = runCatching {
    val safe = poNo.replace(Regex("[^A-Za-z0-9_-]"), "_").ifBlank { "po" }
    val file = File(context.cacheDir, "PO-$safe.pdf")
    file.writeBytes(bytes)
    val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
    val intent = if (share) {
        Intent.createChooser(
            Intent(Intent.ACTION_SEND).apply {
                type = "application/pdf"
                putExtra(Intent.EXTRA_STREAM, uri)
                putExtra(Intent.EXTRA_SUBJECT, "Purchase Order $poNo")
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            },
            "Share purchase order",
        )
    } else {
        Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/pdf")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
    }
    context.startActivity(intent)
}.exceptionOrNull()?.let { if (it is ActivityNotFoundException) "No PDF viewer app found on this phone." else poErr(it, "Could not open the PO PDF.") }

// ───────────────────────── Module ─────────────────────────

@Composable
fun PurchaseOrderModuleScreen(vm: AppViewModel, onBack: () -> Unit, openAuto: Boolean = false) {
    val repo = remember { vm.purchaseOrders() }
    val canEdit = vm.canEdit("PURCHASE_ORDER")
    val scope = rememberCoroutineScope()
    var view by remember { mutableStateOf(PoView.List) }
    var editId by remember { mutableStateOf<Int?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var listEpoch by remember { mutableIntStateOf(0) }
    var distributors by remember { mutableStateOf<List<PoDist>>(emptyList()) }
    var distLoading by remember { mutableStateOf(false) }
    var distLoaded by remember { mutableStateOf(false) }
    val filters = remember { PoListFilters() }
    val auto = remember { PoAutoState() }

    fun loadDistributors(force: Boolean = false, thenPickName: String? = null, onPicked: ((PoDist) -> Unit)? = null) {
        if ((!force && distLoaded) || distLoading) {
            if (thenPickName != null && distLoaded) {
                val hit = distributors.firstOrNull { it.name.equals(thenPickName, true) }
                    ?: distributors.firstOrNull { thenPickName.isNotBlank() && it.name.contains(thenPickName, true) }
                if (hit != null) onPicked?.invoke(hit)
            }
            return
        }
        distLoading = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.distributors() } }
                .onSuccess { list ->
                    distributors = list.map { PoDist(it.jInt("id"), it.str("name").trim()) }
                    distLoaded = true
                    if (thenPickName != null) {
                        val hit = distributors.firstOrNull { it.name.equals(thenPickName, true) }
                            ?: distributors.firstOrNull { thenPickName.isNotBlank() && it.name.contains(thenPickName, true) }
                        if (hit != null) onPicked?.invoke(hit)
                    }
                }
            distLoading = false
        }
    }

    fun ensureDistributors() = loadDistributors()

    fun backToList(message: String?) {
        view = PoView.List
        editId = null
        auto.groups = null
        if (message != null) toast = message
        listEpoch++
    }

    fun openAutoView() {
        if (!canEdit) return
        ensureDistributors()
        auto.groups = null
        auto.reqDays = 0
        auto.filter = ""
        auto.error = null
        auto.rows = emptyList()
        auto.loading = true
        view = PoView.Auto
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.shortBook() } }
                .onSuccess { sb ->
                    auto.months = sb.arr("months").mapNotNull { runCatching { it.asString }.getOrNull() }
                    auto.rows = sb.arr("products")
                        .mapNotNull { e -> e.takeIf { it.isJsonObject }?.asJsonObject?.let(::poSbFromJson) }
                        .distinctBy { it.bindingId }
                }
                .onFailure { auto.error = poErr(it, "Could not load low-stock products.") }
            auto.loading = false
        }
    }

    LaunchedEffect(Unit) { if (openAuto) openAutoView() }
    LaunchedEffect(toast) {
        if (toast != null) {
            delay(5000)
            toast = null
        }
    }
    LaunchedEffect(distributors) {
        auto.groups = auto.groups?.map { poResolveGroup(it, distributors) }
    }

    BackHandler(enabled = view == PoView.Auto) {
        if (auto.groups != null) auto.groups = null else backToList(null)
    }

    when (view) {
        PoView.List -> PoListView(
            repo = repo,
            canEdit = canEdit,
            filters = filters,
            epoch = listEpoch,
            toast = toast,
            onDismissToast = { toast = null },
            onBack = onBack,
            onCustomise = {
                if (canEdit) {
                    ensureDistributors()
                    editId = null
                    view = PoView.Customise
                }
            },
            onAuto = { openAutoView() },
            onEdit = { id ->
                if (canEdit) {
                    ensureDistributors()
                    editId = id
                    view = PoView.Customise
                }
            },
        )
        PoView.Customise -> PoCustomiseView(
            vm = vm,
            repo = repo,
            editId = editId,
            distributors = distributors,
            distLoading = distLoading,
            onDistributorsReload = { name, pick -> loadDistributors(force = true, thenPickName = name, onPicked = pick) },
            onCancel = { backToList(null) },
            onSaved = { backToList(it) },
        )
        PoView.Auto -> PoAutoView(
            vm = vm,
            repo = repo,
            auto = auto,
            distributors = distributors,
            distLoading = distLoading,
            onDistributorsReload = { name, pick -> loadDistributors(force = true, thenPickName = name, onPicked = pick) },
            toast = toast,
            onDismissToast = { toast = null },
            onBack = { backToList(null) },
            onToast = { toast = it },
            onAllSaved = { backToList(it) },
        )
    }
}

// ───────────────────────── Shared bits ─────────────────────────

@Composable
internal fun PoHeader(title: String, subtitle: String, onBack: () -> Unit, trailing: @Composable RowScope.() -> Unit = {}) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        PharmaOutlinedButton("Back", modifier = Modifier.heightIn(max = 34.dp), onClick = onBack)
        Column(Modifier.padding(start = 6.dp).weight(1f)) {
            Text(title, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(subtitle, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        trailing()
    }
}

@Composable
internal fun PoToast(text: String, onDismiss: () -> Unit) {
    Surface(color = Color(0xFFDCFCE7), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(start = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.CheckCircle, null, Modifier.size(16.dp), tint = Color(0xFF15803D))
            Spacer(Modifier.width(6.dp))
            Text(text, Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = Color(0xFF14532D))
            TextButton(onClick = onDismiss) { Text("OK", style = MaterialTheme.typography.labelSmall) }
        }
    }
}

@Composable
internal fun PoChip(text: String, selected: Boolean, onClick: () -> Unit) {
    Box(
        Modifier
            .height(SalesDenseFieldHeight)
            .clip(RoundedCornerShape(6.dp))
            .background(if (selected) PharmaTeal else Color.White)
            .border(1.dp, if (selected) PharmaTeal else PFieldBorder, RoundedCornerShape(6.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 10.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            text,
            style = MaterialTheme.typography.labelMedium,
            color = if (selected) Color.White else PharmaSlate,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
            maxLines = 1,
        )
    }
}

@Composable
private fun PoTypeBadge(type: String) {
    val isAuto = type.equals("Auto", ignoreCase = true)
    Text(
        type.ifBlank { "—" },
        Modifier
            .clip(RoundedCornerShape(10.dp))
            .background(if (isAuto) PoAmberBg else PoBlueBg)
            .padding(horizontal = 7.dp, vertical = 1.dp),
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.SemiBold,
        color = if (isAuto) PoAmberText else PoBlueText,
        maxLines = 1,
    )
}

@Composable
internal fun PoBottomBar(content: @Composable ColumnScope.() -> Unit) {
    Surface(color = Color.White, shadowElevation = 6.dp, modifier = Modifier.fillMaxWidth()) {
        Column(
            Modifier
                .fillMaxWidth()
                .border(BorderStroke(1.dp, PharmaBorder))
                .padding(horizontal = 10.dp, vertical = 6.dp)
                .navigationBarsPadding()
                .imePadding(),
            verticalArrangement = Arrangement.spacedBy(4.dp),
            content = content,
        )
    }
}

@Composable
private fun PoDistributorSheet(
    vm: AppViewModel,
    distributors: List<PoDist>,
    loading: Boolean,
    selectedId: Int?,
    hint: String?,
    onPick: (PoDist) -> Unit,
    onCreatedName: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    var q by remember { mutableStateOf("") }
    var quickCreate by remember { mutableStateOf(false) }
    val canAdd = vm.canEdit("MASTERS") || vm.hasFullAccess()
    val filtered = remember(q, distributors) {
        val k = q.trim().lowercase()
        (if (k.isEmpty()) distributors else distributors.filter { it.name.lowercase().contains(k) }).take(200)
    }
    if (quickCreate) {
        val nameSnapshot = q.trim().ifBlank { hint.orEmpty() }
        MasterQuickCreateSheet(
            vm = vm,
            masterKey = "distributors",
            prefillName = nameSnapshot,
            onDismiss = { quickCreate = false },
            onCreated = {
                quickCreate = false
                onCreatedName(nameSnapshot)
            },
        )
        return
    }
    PSearchSheet(
        title = "Select distributor",
        subtitle = when {
            loading -> "Loading distributors…"
            !hint.isNullOrBlank() -> "Last purchased from “$hint” — not in Distributor Master"
            else -> "${distributors.size} distributor(s)"
        },
        query = q,
        onQuery = { q = it },
        placeholder = "Search distributor…",
        loading = loading,
        onDismiss = onDismiss,
    ) {
        if (filtered.isEmpty() && !loading) {
            item {
                Text(
                    if (distributors.isEmpty()) {
                        "No distributors found — add one in Distributor Master"
                    } else {
                        "No distributor matches “${q.trim()}”."
                    },
                    Modifier.padding(10.dp),
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
            }
        }
        items(filtered) { d -> PSheetRow(d.name, "", selected = d.id == selectedId) { onPick(d) } }
        if (canAdd) {
            item {
                MasterAddNewRow(
                    label = masterAddNewLabel("distributors", q.trim().ifBlank { hint.orEmpty() }),
                    onClick = { quickCreate = true },
                )
            }
        }
    }
}

// ───────────────────────── List ─────────────────────────

@Composable
private fun PoListView(
    repo: PurchaseOrderRepository,
    canEdit: Boolean,
    filters: PoListFilters,
    epoch: Int,
    toast: String?,
    onDismissToast: () -> Unit,
    onBack: () -> Unit,
    onCustomise: () -> Unit,
    onAuto: () -> Unit,
    onEdit: (Int) -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val wide = LocalConfiguration.current.screenWidthDp >= 600
    var search by remember { mutableStateOf(filters.searchDraft.trim()) }
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var detail by remember { mutableStateOf<JsonObject?>(null) }
    var detailLoadingId by remember { mutableIntStateOf(0) }
    var pdfBusyId by remember { mutableIntStateOf(0) }

    LaunchedEffect(filters.searchDraft) {
        delay(300)
        search = filters.searchDraft.trim()
    }
    LaunchedEffect(search, filters.fromDate, filters.toDate, filters.poType, epoch) {
        loading = true
        error = null
        runCatching {
            withContext(Dispatchers.IO) { repo.list(search, filters.fromDate, filters.toDate, filters.poType) }
        }.onSuccess { rows = it }.onFailure { error = poErr(it, "Could not load purchase orders.") }
        loading = false
    }

    fun openDetail(id: Int) {
        if (detailLoadingId != 0) return
        detailLoadingId = id
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.get(id) } }
                .onSuccess { detail = it }
                .onFailure { error = poErr(it, "Could not load purchase order.") }
            detailLoadingId = 0
        }
    }

    fun pdf(id: Int, poNo: String, share: Boolean) {
        if (pdfBusyId != 0) return
        pdfBusyId = id
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.pdfBytes(id) } }
                .onSuccess { bytes -> poPdfIntent(context, bytes, poNo, share)?.let { error = it } }
                .onFailure { error = "Could not open the PO PDF." }
            pdfBusyId = 0
        }
    }

    val kpis = remember(rows) {
        listOf(
            "Orders" to rows.size.toString(),
            "Customise" to rows.count { it.str("po_type") == "Customise" }.toString(),
            "Auto" to rows.count { it.str("po_type") == "Auto" }.toString(),
            "Products" to rows.sumOf { it.jInt("total_items") }.toString(),
        )
    }
    val filtered = filters.searchDraft.isNotBlank() || filters.fromDate.isNotBlank() ||
        filters.toDate != LocalDate.now().toString() || filters.poType.isNotBlank()

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(5.dp)) {
        PoHeader("Purchase Order", "Customise orders and auto short-book orders for distributors", onBack)
        if (canEdit) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PharmaPrimaryButton("New PO", modifier = Modifier.weight(1f), onClick = onCustomise)
                PharmaOutlinedButton("Auto-Purchase", modifier = Modifier.weight(1f), onClick = onAuto)
            }
        }
        toast?.let { PoToast(it, onDismissToast) }
        error?.let { ErrorBanner(it) { error = null } }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            kpis.forEach { (label, value) -> SalesLogKpiChip(label, value, Modifier.weight(1f)) }
        }
        SalesDenseSearchField(
            value = filters.searchDraft,
            onValueChange = { filters.searchDraft = it },
            placeholder = "PO no, distributor or product…",
            modifier = Modifier.fillMaxWidth(),
            leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
            trailingIcon = {
                if (filters.searchDraft.isNotEmpty()) {
                    IconButton(onClick = { filters.searchDraft = "" }, modifier = Modifier.size(22.dp)) {
                        Icon(Icons.Default.Close, "Clear", Modifier.size(14.dp), tint = PharmaMuted)
                    }
                }
            },
        )
        if (wide) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
                PDateField("From", filters.fromDate, { filters.fromDate = it }, Modifier.weight(1f))
                PDateField("To", filters.toDate, { filters.toDate = it }, Modifier.weight(1f))
                PoTypeChips(filters, filtered)
            }
        } else {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PDateField("From", filters.fromDate, { filters.fromDate = it }, Modifier.weight(1f))
                PDateField("To", filters.toDate, { filters.toDate = it }, Modifier.weight(1f))
            }
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) { PoTypeChips(filters, filtered) }
        }
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                loading && rows.isEmpty() -> LoadingBlock("Loading purchase orders…")
                rows.isEmpty() -> PharmaCard {
                    Column(Modifier.fillMaxWidth().padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Text("No purchase orders found.", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Spacer(Modifier.height(4.dp))
                        Text(
                            if (filtered) "Try clearing the filters."
                            else "Use New PO to build an order manually, or Auto-Purchase to order from the short book.",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                            textAlign = TextAlign.Center,
                        )
                    }
                }
                else -> LazyVerticalGrid(
                    columns = GridCells.Adaptive(minSize = 320.dp),
                    modifier = Modifier.fillMaxSize(),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    items(rows, key = { it.jInt("id") }) { r ->
                        val id = r.jInt("id")
                        PoListCard(
                            r = r,
                            canEdit = canEdit,
                            viewBusy = detailLoadingId == id,
                            pdfBusy = pdfBusyId == id,
                            onView = { openDetail(id) },
                            onEdit = { onEdit(id) },
                            onPdf = { pdf(id, r.str("po_no"), share = false) },
                        )
                    }
                    item(span = { GridItemSpan(maxLineSpan) }) { Spacer(Modifier.height(8.dp)) }
                }
            }
            if (loading && rows.isNotEmpty()) {
                LinearProgressIndicator(Modifier.fillMaxWidth().align(Alignment.TopCenter), color = PharmaTeal)
            }
        }
    }

    detail?.let { po ->
        val id = po.jInt("id")
        PoDetailSheet(
            po = po,
            canEdit = canEdit,
            pdfBusy = pdfBusyId == id,
            onEdit = {
                detail = null
                onEdit(id)
            },
            onPdf = { pdf(id, po.str("po_no"), share = false) },
            onShare = { pdf(id, po.str("po_no"), share = true) },
            onDismiss = { detail = null },
        )
    }
}

@Composable
private fun PoTypeChips(filters: PoListFilters, filtered: Boolean) {
    Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
        PoChip("All", filters.poType.isBlank()) { filters.poType = "" }
        PoChip("Customise", filters.poType == "Customise") { filters.poType = "Customise" }
        PoChip("Auto", filters.poType == "Auto") { filters.poType = "Auto" }
        if (filtered) {
            TextButton(
                onClick = {
                    filters.searchDraft = ""
                    filters.fromDate = ""
                    filters.toDate = LocalDate.now().toString()
                    filters.poType = ""
                },
                contentPadding = PaddingValues(horizontal = 6.dp),
            ) { Text("Reset", style = MaterialTheme.typography.labelMedium) }
        }
    }
}

@Composable
private fun PoListCard(
    r: JsonObject,
    canEdit: Boolean,
    viewBusy: Boolean,
    pdfBusy: Boolean,
    onView: () -> Unit,
    onEdit: () -> Unit,
    onPdf: () -> Unit,
) {
    val items = r.jInt("total_items")
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = Color.White,
        border = BorderStroke(1.dp, PharmaBorder),
        modifier = Modifier.fillMaxWidth().clickable(onClick = onView),
    ) {
        Column(Modifier.padding(start = 10.dp, end = 4.dp, top = 6.dp, bottom = 2.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    r.str("po_no").ifBlank { "#${r.jInt("id")}" },
                    Modifier.weight(1f, fill = false),
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.Bold,
                    color = PharmaSlate,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Spacer(Modifier.width(6.dp))
                PoTypeBadge(r.str("po_type"))
                Spacer(Modifier.weight(1f))
                Text(
                    formatSaleDate(r.str("order_date").ifBlank { r.str("created_at") }),
                    Modifier.padding(end = 6.dp),
                    style = MaterialTheme.typography.labelMedium,
                    color = PharmaMuted,
                )
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(
                        r.str("distributor_name").ifBlank { "—" },
                        style = MaterialTheme.typography.bodySmall,
                        color = PharmaSlate,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    Text("$items product${if (items == 1) "" else "s"}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
                SalesLogIconAction(onClick = onView, contentDescription = "View") {
                    if (viewBusy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                    else Icon(Icons.Default.Visibility, null, Modifier.size(18.dp), tint = Color(0xFF059669))
                }
                if (canEdit) {
                    SalesLogIconAction(onClick = onEdit, contentDescription = "Edit") {
                        Icon(Icons.Default.Edit, null, Modifier.size(17.dp), tint = Color(0xFF0284C7))
                    }
                }
                SalesLogIconAction(onClick = onPdf, contentDescription = "PDF") {
                    if (pdfBusy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                    else Icon(Icons.Default.PictureAsPdf, null, Modifier.size(18.dp), tint = Color(0xFFDC2626))
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PoDetailSheet(
    po: JsonObject,
    canEdit: Boolean,
    pdfBusy: Boolean,
    onEdit: () -> Unit,
    onPdf: () -> Unit,
    onShare: () -> Unit,
    onDismiss: () -> Unit,
) {
    val items = remember(po) { po.arr("items").mapNotNull { e -> e.takeIf { it.isJsonObject }?.asJsonObject?.let(::poLineFromJson) } }
    val totalQty = items.sumOf { it.qty }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 12.dp).navigationBarsPadding()) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "Purchase Order · ${po.str("po_no")}",
                    Modifier.weight(1f),
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    color = PharmaSlate,
                )
                PoTypeBadge(po.str("po_type"))
            }
            Spacer(Modifier.height(6.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PReadField("PO No", po.str("po_no").ifBlank { "—" }, Modifier.weight(1f), highlight = true)
                PReadField("Date", formatSaleDate(po.str("order_date").ifBlank { po.str("created_at") }), Modifier.weight(1f))
            }
            Spacer(Modifier.height(4.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PReadField("Type", po.str("po_type").ifBlank { "—" }, Modifier.weight(1f))
                PReadField("Distributor", po.str("distributor_name").ifBlank { "—" }, Modifier.weight(2f))
            }
            Spacer(Modifier.height(8.dp))
            Text(
                "ITEMS · ${items.size} product(s) · Total qty ${poQty(totalQty)}",
                style = MaterialTheme.typography.labelSmall,
                fontWeight = FontWeight.Bold,
                color = PharmaSlate,
            )
            Spacer(Modifier.height(2.dp))
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(6.dp)).border(1.dp, PharmaBorder, RoundedCornerShape(6.dp))) {
                Row(Modifier.fillMaxWidth().background(BillGridHeaderBg), verticalAlignment = Alignment.CenterVertically) {
                    BillGridTh("#", 26.dp)
                    Text("PRODUCT", Modifier.weight(1f).padding(horizontal = 2.dp, vertical = 6.dp), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = Color(0xFF64748B))
                    BillGridTh("PACK", 66.dp)
                    BillGridTh("CATEGORY", 74.dp)
                    BillGridTh("QTY", 48.dp, alignEnd = true)
                }
                LazyColumn(Modifier.fillMaxWidth().heightIn(max = 340.dp)) {
                    itemsIndexed(items, key = { _, l -> l.key }) { i, l ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                            BillGridCellText("${i + 1}", 26.dp, color = PharmaMuted)
                            Column(Modifier.weight(1f).padding(horizontal = 2.dp)) {
                                Text(l.name, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                val sub = listOf(l.manufacturer, l.generic).filter { it.isNotBlank() }.joinToString(" · ")
                                if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            }
                            BillGridCellText(l.pack.ifBlank { "—" }, 66.dp)
                            BillGridCellText(l.category.ifBlank { "—" }, 74.dp)
                            BillGridCellText(poQty(l.qty), 48.dp, alignEnd = true, fontWeight = FontWeight.SemiBold)
                        }
                        if (i < items.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.45f))
                    }
                }
                if (items.isEmpty()) {
                    Text("No items on this PO.", Modifier.padding(10.dp), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
            }
            Spacer(Modifier.height(10.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PharmaOutlinedButton("Close", modifier = Modifier.weight(1f), onClick = onDismiss)
                if (canEdit) PharmaOutlinedButton("Edit", modifier = Modifier.weight(1f), onClick = onEdit)
                PharmaOutlinedButton(if (pdfBusy) "…" else "Share", modifier = Modifier.weight(1f), enabled = !pdfBusy, onClick = onShare)
                PharmaPrimaryButton(if (pdfBusy) "Opening…" else "PDF", modifier = Modifier.weight(1f), enabled = !pdfBusy, onClick = onPdf)
            }
            Spacer(Modifier.height(12.dp))
        }
    }
}

// ───────────────────────── Customise / Edit ─────────────────────────

@Composable
private fun PoCustomiseView(
    vm: AppViewModel,
    repo: PurchaseOrderRepository,
    editId: Int?,
    distributors: List<PoDist>,
    distLoading: Boolean,
    onDistributorsReload: (preferredName: String?, onPicked: (PoDist) -> Unit) -> Unit,
    onCancel: () -> Unit,
    onSaved: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current
    val wide = LocalConfiguration.current.screenWidthDp >= 600
    val qtyFocus = remember { FocusRequester() }
    var editing by remember { mutableStateOf<JsonObject?>(null) }
    var loading by remember { mutableStateOf(editId != null) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var dist by remember { mutableStateOf<PoDist?>(null) }
    var lines by remember { mutableStateOf<List<PoLine>>(emptyList()) }
    var query by remember { mutableStateOf("") }
    var hits by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    var resultsOpen by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<JsonObject?>(null) }
    var qtyText by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    var distSheet by remember { mutableStateOf(false) }
    var confirmLeave by remember { mutableStateOf(false) }
    var dirty by remember { mutableStateOf(false) }
    var quickProduct by remember { mutableStateOf(false) }
    val canQuickAddMaster = vm.canEdit("MASTERS") || vm.hasFullAccess()

    LaunchedEffect(editId) {
        if (editId == null) return@LaunchedEffect
        runCatching { withContext(Dispatchers.IO) { repo.get(editId) } }
            .onSuccess { po ->
                editing = po
                val name = po.str("distributor_name").trim()
                dist = if (name.isNotBlank()) PoDist(po.jInt("distributor_id"), name) else null
                lines = po.arr("items").mapNotNull { e -> e.takeIf { it.isJsonObject }?.asJsonObject?.let(::poLineFromJson) }
            }
            .onFailure { loadError = poErr(it, "Could not load purchase order.") }
        loading = false
    }

    LaunchedEffect(query, selected) {
        if (selected != null) return@LaunchedEffect
        val q = query.trim()
        if (q.length < 2) {
            hits = emptyList()
            searching = false
            resultsOpen = false
            return@LaunchedEffect
        }
        resultsOpen = true
        searching = true
        delay(250)
        runCatching { withContext(Dispatchers.IO) { repo.searchProducts(q, 30) } }
            .onSuccess { hits = it }
            .onFailure { hits = emptyList() }
        searching = false
    }

    fun leave() {
        if (dirty && !saving) confirmLeave = true else onCancel()
    }
    BackHandler { leave() }

    fun resetProductEntry() {
        query = ""
        selected = null
        hits = emptyList()
        resultsOpen = false
        qtyText = ""
    }

    fun pick(p: JsonObject) {
        selected = p
        query = p.str("name")
        resultsOpen = false
        error = null
        scope.launch {
            delay(60)
            runCatching { qtyFocus.requestFocus() }
        }
    }

    fun addLine() {
        val p = selected ?: run { error = "Select a product from the search list."; return }
        val qty = qtyText.toDoubleOrNull() ?: 0.0
        if (qty <= 0) {
            error = "Enter a qty greater than 0."
            runCatching { qtyFocus.requestFocus() }
            return
        }
        error = null
        val id = p.jInt("id")
        val idx = lines.indexOfFirst { it.masterId == id }
        lines = if (idx >= 0) {
            lines.mapIndexed { i, l -> if (i == idx) l.copy(qty = poR2(l.qty + qty)) else l }
        } else {
            lines + PoLine(
                masterId = id,
                name = p.str("name"),
                pack = p.str("pack_size"),
                category = p.str("product_category"),
                dosage = p.str("dosage_form"),
                mrp = p.amount("mrp"),
                hsn = p.str("hsn_code"),
                manufacturer = p.str("manufacturer"),
                generic = p.str("generic_name"),
                qty = qty,
            )
        }
        dirty = true
        resetProductEntry()
    }

    fun save() {
        val d = dist ?: run { error = "Please select a distributor."; return }
        if (lines.isEmpty()) { error = "Add at least one product."; return }
        lines.firstOrNull { it.qty <= 0 }?.let { error = "Enter qty for ${it.name}."; return }
        focus.clearFocus()
        saving = true
        error = null
        val po = editing
        val body = JsonObject().apply {
            addProperty("po_type", po?.str("po_type")?.ifBlank { null } ?: "Customise")
            if (d.id > 0) addProperty("distributor_id", d.id) else add("distributor_id", JsonNull.INSTANCE)
            addProperty("distributor_name", d.name)
            add("items", JsonArray().apply { lines.forEach { add(poLineToJson(it)) } })
        }
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) { if (editId != null) repo.update(editId, body) else repo.create(body) }
            }.onSuccess { res ->
                val no = res.str("po_no").ifBlank { po?.str("po_no").orEmpty() }
                onSaved(
                    if (editId != null) "Purchase Order updated successfully. PO NO: $no"
                    else "Purchase Order saved successfully. PO NO: $no",
                )
            }.onFailure {
                error = poErr(it, "Could not save purchase order.")
                saving = false
            }
        }
    }

    val poType = editing?.str("po_type")?.ifBlank { null } ?: "Customise"
    val poDate = editing?.str("order_date")?.ifBlank { null }?.let { formatSaleDate(it) }
        ?: LocalDate.now().format(DateTimeFormatter.ofPattern("dd-MM-yyyy"))
    val totalQty = poR2(lines.sumOf { it.qty })
    val title = if (editId != null) "Edit PO · ${editing?.str("po_no").orEmpty()}" else "New PO"

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            PoHeader(
                title,
                if (editId != null) "Add products, change qty, remove lines or switch distributor" else "Pick a distributor, add products and quantities",
                onBack = { leave() },
            )
            when {
                loading -> LoadingBlock("Loading PO…")
                loadError != null -> ErrorBanner(loadError!!)
                else -> LazyColumn(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    item {
                        PSection("Order details", expanded = true, onToggle = null, summary = "") {
                            val distField: @Composable (Modifier) -> Unit = { m ->
                                Row(m, verticalAlignment = Alignment.Bottom) {
                                    PPickField(
                                        label = "Distributor *",
                                        value = dist?.name.orEmpty(),
                                        placeholder = if (distLoading && distributors.isEmpty()) "Loading distributors…" else "Select distributor",
                                        onClick = { distSheet = true },
                                        modifier = Modifier.weight(1f),
                                    )
                                    if (dist != null) {
                                        IconButton(onClick = { dist = null; dirty = true }, modifier = Modifier.size(SalesDenseFieldHeight)) {
                                            Icon(Icons.Default.Close, "Clear distributor", Modifier.size(16.dp), tint = PharmaMuted)
                                        }
                                    }
                                }
                            }
                            if (wide) {
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    distField(Modifier.weight(2f))
                                    PReadField("PO Date", poDate, Modifier.weight(1f))
                                    PReadField("PO Type", poType, Modifier.weight(1f), highlight = true)
                                }
                            } else {
                                distField(Modifier.fillMaxWidth())
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    PReadField("PO Date", poDate, Modifier.weight(1f))
                                    PReadField("PO Type", poType, Modifier.weight(1f), highlight = true)
                                }
                            }
                        }
                    }
                    item {
                        PSection("Add product", expanded = true, onToggle = null, summary = "") {
                            PLabel("Product (type at least 2 letters)")
                            SalesDenseSearchField(
                                value = query,
                                onValueChange = {
                                    query = it
                                    selected = null
                                    error = null
                                },
                                placeholder = "Search product name…",
                                modifier = Modifier.fillMaxWidth(),
                                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                                trailingIcon = {
                                    when {
                                        searching -> CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                                        query.isNotEmpty() -> IconButton(onClick = { resetProductEntry() }, modifier = Modifier.size(22.dp)) {
                                            Icon(Icons.Default.Close, "Clear", Modifier.size(14.dp), tint = PharmaMuted)
                                        }
                                    }
                                },
                                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                                keyboardActions = KeyboardActions(onSearch = { hits.firstOrNull()?.let { pick(it) } }),
                            )
                            if (resultsOpen && selected == null) {
                                PoProductResults(
                                    hits = hits,
                                    searching = searching,
                                    query = query,
                                    canAddNew = canQuickAddMaster,
                                    onPick = { pick(it) },
                                    onAddNew = { quickProduct = true },
                                )
                            }
                            selected?.let { PoFactsPanel(it, wide) }
                            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                Column(Modifier.weight(1f)) {
                                    PLabel("Qty (packs) *")
                                    Spacer(Modifier.height(1.dp))
                                    SalesDenseSearchField(
                                        value = qtyText,
                                        onValueChange = { qtyText = pCleanNum(it, false) },
                                        placeholder = "0",
                                        modifier = Modifier.fillMaxWidth().focusRequester(qtyFocus),
                                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done),
                                        keyboardActions = KeyboardActions(onDone = { addLine() }),
                                    )
                                }
                                Button(
                                    onClick = { addLine() },
                                    enabled = selected != null,
                                    colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal),
                                    contentPadding = PaddingValues(horizontal = 12.dp),
                                    shape = RoundedCornerShape(6.dp),
                                    modifier = Modifier.height(SalesDenseFieldHeight + 2.dp),
                                ) {
                                    Icon(Icons.Default.Add, null, Modifier.size(16.dp))
                                    Spacer(Modifier.width(4.dp))
                                    Text("Add to order", style = MaterialTheme.typography.labelMedium)
                                }
                            }
                        }
                    }
                    item {
                        Row(Modifier.fillMaxWidth().padding(top = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text("ORDER ITEMS", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
                            Spacer(Modifier.weight(1f))
                            Text(
                                "${lines.size} product(s) · Total qty ${poQty(totalQty)}",
                                style = MaterialTheme.typography.labelMedium,
                                color = PharmaTeal,
                                fontWeight = FontWeight.SemiBold,
                            )
                        }
                    }
                    if (lines.isEmpty()) {
                        item {
                            PharmaCard {
                                Text(
                                    "No products yet — search above and tap “Add to order”.",
                                    Modifier.fillMaxWidth().padding(14.dp),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaMuted,
                                    textAlign = TextAlign.Center,
                                )
                            }
                        }
                    } else {
                        if (wide) item { PoLinesHeader() }
                        itemsIndexed(lines, key = { _, l -> l.key }) { i, line ->
                            PoLineRow(
                                index = i,
                                line = line,
                                wide = wide,
                                onQty = { v ->
                                    dirty = true
                                    lines = lines.map { if (it.key == line.key) it.copy(qty = v) else it }
                                },
                                onRemove = {
                                    dirty = true
                                    lines = lines.filter { it.key != line.key }
                                },
                            )
                        }
                    }
                    item { Spacer(Modifier.height(8.dp)) }
                }
            }
        }
        if (!loading && loadError == null) {
            PoBottomBar {
                error?.let { ErrorBanner(it) { error = null } }
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            dist?.name ?: "No distributor selected",
                            style = MaterialTheme.typography.labelMedium,
                            fontWeight = FontWeight.SemiBold,
                            color = if (dist == null) MaterialTheme.colorScheme.error else PharmaSlate,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                        Text("${lines.size} product(s) · Qty ${poQty(totalQty)}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    }
                    PharmaOutlinedButton("Cancel", enabled = !saving, onClick = { leave() })
                    PharmaPrimaryButton(
                        when {
                            saving -> "Saving…"
                            editId != null -> "Update PO"
                            else -> "Save PO"
                        },
                        enabled = !saving,
                        onClick = { save() },
                    )
                }
            }
        }
    }

    if (distSheet) {
        PoDistributorSheet(
            vm = vm,
            distributors = distributors,
            loading = distLoading,
            selectedId = dist?.id,
            hint = null,
            onPick = {
                dist = it
                dirty = true
                error = null
                distSheet = false
            },
            onCreatedName = { name ->
                distSheet = false
                onDistributorsReload(name) { d ->
                    dist = d
                    dirty = true
                    error = null
                }
            },
            onDismiss = { distSheet = false },
        )
    }
    if (quickProduct) {
        val nameSnapshot = query.trim()
        MasterQuickCreateSheet(
            vm = vm,
            masterKey = "products",
            prefillName = nameSnapshot,
            onDismiss = { quickProduct = false },
            onCreated = {
                quickProduct = false
                scope.launch {
                    val q = nameSnapshot.ifBlank { query }.trim()
                    val list = if (q.length < 2) emptyList()
                    else runCatching { withContext(Dispatchers.IO) { repo.searchProducts(q) } }.getOrDefault(emptyList())
                    hits = list
                    resultsOpen = true
                    val hit = list.firstOrNull { it.str("name").equals(nameSnapshot, true) } ?: list.firstOrNull()
                    if (hit != null) pick(hit)
                    else error = "Product created. Search and select it to continue."
                }
            },
        )
    }
    if (confirmLeave) {
        AlertDialog(
            onDismissRequest = { confirmLeave = false },
            title = { Text("Discard this PO?", style = MaterialTheme.typography.titleSmall) },
            text = { Text("Your unsaved changes will be lost.", style = MaterialTheme.typography.bodySmall) },
            confirmButton = {
                TextButton(onClick = {
                    confirmLeave = false
                    onCancel()
                }) { Text("Discard", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmLeave = false }) { Text("Keep editing") } },
        )
    }
}

@Composable
private fun PoProductResults(
    hits: List<JsonObject>,
    searching: Boolean,
    query: String,
    canAddNew: Boolean = false,
    onPick: (JsonObject) -> Unit,
    onAddNew: () -> Unit = {},
) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .border(1.dp, PFieldBorder, RoundedCornerShape(6.dp))
            .heightIn(min = 40.dp, max = 280.dp)
            .verticalScroll(rememberScrollState()),
    ) {
        if (hits.isEmpty()) {
            Text(
                if (searching) "Searching…" else if (query.isNotBlank()) "No products match “${query.trim()}”." else "Type to search…",
                Modifier.padding(10.dp),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
        }
        hits.forEachIndexed { i, p ->
            val sub = listOf(p.str("pack_size"), p.str("manufacturer"), p.str("product_category"))
                .filter { it.isNotBlank() }.joinToString(" · ")
            Row(
                Modifier
                    .fillMaxWidth()
                    .clickable { onPick(p) }
                    .padding(horizontal = 8.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(Modifier.weight(1f)) {
                    Text(p.str("name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                if (p.amount("mrp") > 0) {
                    Text("MRP ${formatAmount2(p.amount("mrp"))}", style = MaterialTheme.typography.labelSmall, color = PharmaTeal, fontWeight = FontWeight.SemiBold)
                }
            }
            if (i < hits.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.4f))
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
private fun PoFactsPanel(p: JsonObject, wide: Boolean) {
    val facts = listOf(
        "Category" to p.str("product_category"),
        "Pack" to p.str("pack_size"),
        "Dosage" to p.str("dosage_form"),
        "MRP" to (if (p.amount("mrp") > 0) formatAmount2(p.amount("mrp")) else ""),
        "HSN" to p.str("hsn_code"),
        "Manufacturer" to p.str("manufacturer"),
        "Generic" to p.str("generic_name"),
    )
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .background(PoFactsBg)
            .border(1.dp, PharmaTeal.copy(alpha = 0.25f), RoundedCornerShape(6.dp))
            .padding(8.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(p.str("name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaTeal, maxLines = 2)
        facts.chunked(if (wide) 4 else 2).forEach { chunk ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                chunk.forEach { (label, value) ->
                    Column(Modifier.weight(1f)) {
                        Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        Text(value.ifBlank { "—" }, style = MaterialTheme.typography.labelMedium, color = PharmaSlate, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    }
                }
                repeat((if (wide) 4 else 2) - chunk.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}

@Composable
private fun PoLinesHeader() {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(6.dp)).background(BillGridHeaderBg),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        BillGridTh("#", 28.dp)
        Text("PRODUCT", Modifier.weight(1f).padding(horizontal = 2.dp, vertical = 6.dp), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = Color(0xFF64748B))
        BillGridTh("PACK", 80.dp)
        BillGridTh("CATEGORY", 90.dp)
        BillGridTh("MRP", 72.dp, alignEnd = true)
        BillGridTh("HSN", 72.dp)
        BillGridTh("QTY", 74.dp, alignEnd = true)
        Spacer(Modifier.width(36.dp))
    }
}

@Composable
private fun PoLineRow(index: Int, line: PoLine, wide: Boolean, onQty: (Double) -> Unit, onRemove: () -> Unit) {
    val invalid = line.qty <= 0
    val sub = listOf(line.manufacturer, line.generic).filter { it.isNotBlank() }.joinToString(" · ")
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = Color.White,
        border = BorderStroke(1.dp, if (invalid) MaterialTheme.colorScheme.error.copy(alpha = 0.6f) else PharmaBorder),
        modifier = Modifier.fillMaxWidth(),
    ) {
        if (wide) {
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                BillGridCellText("${index + 1}", 28.dp, color = PharmaMuted)
                Column(Modifier.weight(1f).padding(horizontal = 2.dp)) {
                    Text(line.name, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                BillGridCellText(line.pack.ifBlank { "—" }, 80.dp)
                BillGridCellText(line.category.ifBlank { "—" }, 90.dp)
                BillGridCellText(if (line.mrp > 0) formatAmount2(line.mrp) else "—", 72.dp, alignEnd = true)
                BillGridCellText(line.hsn.ifBlank { "—" }, 72.dp)
                Box(Modifier.width(74.dp).padding(horizontal = 2.dp), contentAlignment = Alignment.CenterEnd) {
                    PCellNum(line.qty, onQty, 66.dp)
                }
                IconButton(onClick = onRemove, modifier = Modifier.size(36.dp)) {
                    Icon(Icons.Default.Delete, "Remove", Modifier.size(18.dp), tint = MaterialTheme.colorScheme.error)
                }
            }
        } else {
            Row(Modifier.fillMaxWidth().padding(start = 8.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("${index + 1}", Modifier.width(20.dp), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                Column(Modifier.weight(1f)) {
                    Text(line.name, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    val meta = listOf(
                        line.pack,
                        line.category,
                        if (line.mrp > 0) "MRP ${formatAmount2(line.mrp)}" else "",
                        if (line.hsn.isNotBlank()) "HSN ${line.hsn}" else "",
                    ).filter { it.isNotBlank() }.joinToString(" · ")
                    if (meta.isNotBlank()) Text(meta, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("Qty", style = MaterialTheme.typography.labelSmall, color = if (invalid) MaterialTheme.colorScheme.error else PharmaMuted)
                    PCellNum(line.qty, onQty, 64.dp)
                }
                IconButton(onClick = onRemove, modifier = Modifier.size(36.dp)) {
                    Icon(Icons.Default.Delete, "Remove", Modifier.size(18.dp), tint = MaterialTheme.colorScheme.error)
                }
            }
        }
    }
}

// ───────────────────────── Auto-Purchase (short book) ─────────────────────────

@Composable
private fun PoAutoView(
    vm: AppViewModel,
    repo: PurchaseOrderRepository,
    auto: PoAutoState,
    distributors: List<PoDist>,
    distLoading: Boolean,
    onDistributorsReload: (preferredName: String?, onPicked: (PoDist) -> Unit) -> Unit,
    toast: String?,
    onDismissToast: () -> Unit,
    onBack: () -> Unit,
    onToast: (String) -> Unit,
    onAllSaved: (String) -> Unit,
) {
    if (auto.groups != null) {
        PoAutoGroupsView(
            vm = vm,
            repo = repo,
            auto = auto,
            distributors = distributors,
            distLoading = distLoading,
            onDistributorsReload = onDistributorsReload,
            toast = toast,
            onDismissToast = onDismissToast,
            onToast = onToast,
            onAllSaved = onAllSaved,
        )
    } else {
        PoShortBookView(auto, toast, onDismissToast, onBack, onMake = { auto.groups = poBuildGroups(it).map { g -> poResolveGroup(g, distributors) } })
    }
}

@Composable
private fun PoShortBookView(
    auto: PoAutoState,
    toast: String?,
    onDismissToast: () -> Unit,
    onBack: () -> Unit,
    onMake: (List<SbRow>) -> Unit,
) {
    val visible = remember(auto.rows, auto.filter) {
        val q = auto.filter.trim().lowercase()
        if (q.isEmpty()) auto.rows else auto.rows.filter {
            it.name.lowercase().contains(q) || it.distName.lowercase().contains(q) || it.manufacturer.lowercase().contains(q)
        }
    }
    val checked = auto.rows.filter { it.checked }
    val allChecked = visible.isNotEmpty() && visible.all { it.checked }

    fun applyDays(days: Int) {
        auto.reqDays = days
        val months = auto.months.size.coerceAtLeast(1)
        auto.rows = auto.rows.map { r ->
            r.copy(reqQty = if (r.totalSold > 0) floor(r.totalSold / (months * 30.0) * days + 0.5) else 0.0)
        }
    }

    fun update(bindingId: Int, f: (SbRow) -> SbRow) {
        auto.rows = auto.rows.map { if (it.bindingId == bindingId) f(it) else it }
    }

    fun make() {
        if (checked.isEmpty()) {
            auto.error = "Please select at least one product."
            return
        }
        checked.firstOrNull { it.reqQty <= 0 }?.let {
            auto.error = "Enter required qty for ${it.name}."
            return
        }
        auto.error = null
        onMake(checked)
    }

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            PoHeader(
                "Auto-Purchase · Short Book",
                "Products at or below minimum stock, or out of stock but sold in the last ${auto.months.size.coerceAtLeast(1)} months. Quantities in packs.",
                onBack,
            )
            toast?.let { PoToast(it, onDismissToast) }
            auto.error?.let { ErrorBanner(it) { auto.error = null } }
            SalesDenseSearchField(
                value = auto.filter,
                onValueChange = { auto.filter = it },
                placeholder = "Filter by product, manufacturer or distributor…",
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                trailingIcon = {
                    if (auto.filter.isNotEmpty()) {
                        IconButton(onClick = { auto.filter = "" }, modifier = Modifier.size(22.dp)) {
                            Icon(Icons.Default.Close, "Clear", Modifier.size(14.dp), tint = PharmaMuted)
                        }
                    }
                },
            )
            Row(
                Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text("Req. qty for", style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                listOf(7, 15, 30, 60).forEach { d -> PoChip("$d days", auto.reqDays == d) { applyDays(d) } }
            }
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Checkbox(
                    checked = allChecked,
                    onCheckedChange = { c ->
                        val ids = visible.map { it.bindingId }.toSet()
                        auto.rows = auto.rows.map { if (it.bindingId in ids) it.copy(checked = c) else it }
                    },
                    enabled = visible.isNotEmpty(),
                    modifier = Modifier.size(32.dp),
                )
                Text("Select all", style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                Spacer(Modifier.weight(1f))
                Text(
                    "${visible.size} of ${auto.rows.size} · ${checked.size} selected",
                    style = MaterialTheme.typography.labelMedium,
                    color = PharmaTeal,
                    fontWeight = FontWeight.SemiBold,
                )
            }
            Box(Modifier.weight(1f).fillMaxWidth()) {
                when {
                    auto.loading -> LoadingBlock("Loading short book…")
                    visible.isEmpty() -> PharmaCard {
                        Text(
                            if (auto.rows.isEmpty()) "No low-stock products right now — everything is above minimum stock."
                            else "No products match the filter.",
                            Modifier.fillMaxWidth().padding(18.dp),
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                            textAlign = TextAlign.Center,
                        )
                    }
                    else -> LazyVerticalGrid(
                        columns = GridCells.Adaptive(minSize = 330.dp),
                        modifier = Modifier.fillMaxSize(),
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                        verticalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        items(visible, key = { it.bindingId }) { r ->
                            PoShortBookCard(
                                r = r,
                                months = auto.months,
                                onCheck = { c -> update(r.bindingId) { it.copy(checked = c) } },
                                onQty = { q -> update(r.bindingId) { it.copy(reqQty = q, checked = q > 0 || it.checked) } },
                            )
                        }
                        item(span = { GridItemSpan(maxLineSpan) }) { Spacer(Modifier.height(8.dp)) }
                    }
                }
            }
        }
        PoBottomBar {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Column(Modifier.weight(1f)) {
                    Text("${checked.size} product(s) selected", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    Text(
                        "Req. qty ${poQty(poR2(checked.sumOf { it.reqQty }))} · ${checked.map { it.distId?.toString() ?: it.distName.lowercase() }.distinct().size} distributor(s)",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                    )
                }
                PharmaPrimaryButton("Make PO (${checked.size})", enabled = !auto.loading, onClick = { make() })
            }
        }
    }
}

@Composable
private fun PoShortBookCard(r: SbRow, months: List<String>, onCheck: (Boolean) -> Unit, onQty: (Double) -> Unit) {
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = if (r.checked) PoFactsBg else Color.White,
        border = BorderStroke(1.dp, if (r.checked) PharmaTeal.copy(alpha = 0.6f) else PharmaBorder),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(start = 2.dp, end = 8.dp, top = 4.dp, bottom = 6.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Checkbox(checked = r.checked, onCheckedChange = onCheck, modifier = Modifier.size(36.dp))
                Column(Modifier.weight(1f).clickable { onCheck(!r.checked) }) {
                    Text(r.name, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    val meta = listOf(
                        r.manufacturer,
                        r.pack,
                        if (r.mrp > 0) "MRP ${formatAmount2(r.mrp)}" else "",
                        r.category,
                    ).filter { it.isNotBlank() }.joinToString(" · ")
                    if (meta.isNotBlank()) Text(meta, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("Req. qty", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    PCellNum(r.reqQty, onQty, 66.dp)
                }
            }
            Row(
                Modifier.fillMaxWidth().padding(start = 8.dp).horizontalScroll(rememberScrollState()),
                horizontalArrangement = Arrangement.spacedBy(3.dp),
            ) {
                months.forEach { m -> PoMonthCell(poMonthLabel(m), poQty(r.soldByMonth[m] ?: 0.0), highlight = false) }
                PoMonthCell("Sold", poQty(r.totalSold), highlight = true)
            }
            Row(Modifier.fillMaxWidth().padding(start = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                val out = r.available <= 0
                Text(
                    "Avail ${poQty(r.available)}",
                    Modifier
                        .clip(RoundedCornerShape(10.dp))
                        .background(if (out) Color(0xFFFEE2E2) else Color(0xFFE0F2FE))
                        .padding(horizontal = 7.dp, vertical = 1.dp),
                    style = MaterialTheme.typography.labelSmall,
                    fontWeight = FontWeight.SemiBold,
                    color = if (out) Color(0xFFB91C1C) else Color(0xFF075985),
                )
                Spacer(Modifier.width(6.dp))
                Text(
                    "Last: ${r.distName.ifBlank { "—" }}",
                    Modifier.weight(1f),
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
    }
}

@Composable
private fun PoMonthCell(label: String, value: String, highlight: Boolean) {
    Column(
        Modifier
            .widthIn(min = 44.dp)
            .clip(RoundedCornerShape(5.dp))
            .background(if (highlight) PharmaTeal.copy(alpha = 0.12f) else BillGridHeaderBg)
            .padding(horizontal = 5.dp, vertical = 2.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1)
        Text(value, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = if (highlight) PharmaTeal else PharmaSlate, maxLines = 1)
    }
}

@Composable
private fun PoAutoGroupsView(
    vm: AppViewModel,
    repo: PurchaseOrderRepository,
    auto: PoAutoState,
    distributors: List<PoDist>,
    distLoading: Boolean,
    onDistributorsReload: (preferredName: String?, onPicked: (PoDist) -> Unit) -> Unit,
    toast: String?,
    onDismissToast: () -> Unit,
    onToast: (String) -> Unit,
    onAllSaved: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val groups = auto.groups.orEmpty()
    var sheetFor by remember { mutableStateOf<String?>(null) }

    fun patch(key: String, f: (AutoGroup) -> AutoGroup) {
        auto.groups = auto.groups?.map { if (it.key == key) f(it) else it }
    }

    fun saveGroup(g: AutoGroup) {
        val id = g.distId
        if (id == null) {
            patch(g.key) { it.copy(error = "Please select a distributor.") }
            return
        }
        val name = distributors.firstOrNull { it.id == id }?.name ?: g.distName
        patch(g.key) { it.copy(saving = true, error = null) }
        val body = JsonObject().apply {
            addProperty("po_type", "Auto")
            addProperty("distributor_id", id)
            addProperty("distributor_name", name)
            add(
                "items",
                JsonArray().apply {
                    g.products.forEach { p ->
                        add(
                            JsonObject().apply {
                                addProperty("product_binding_id", p.bindingId)
                                addProperty("master_product_id", p.masterId)
                                addProperty("product_name", p.name)
                                addProperty("pack_size", p.pack)
                                addProperty("product_category", p.category)
                                addProperty("mrp", p.mrp)
                                addProperty("hsn_code", p.hsn)
                                addProperty("manufacturer", p.manufacturer)
                                addProperty("qty", p.reqQty)
                            },
                        )
                    }
                },
            )
        }
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.create(body) } }
                    .onSuccess { res ->
                        val msg = "Purchase Order saved successfully. PO NO: ${res.str("po_no")}"
                        val ids = g.products.map { it.bindingId }.toSet()
                        auto.rows = auto.rows.filterNot { it.bindingId in ids }
                        val left = auto.groups.orEmpty().filter { it.key != g.key }
                        if (left.isEmpty()) {
                            onAllSaved(msg)
                        } else {
                            auto.groups = left
                            onToast(msg)
                        }
                    }
                    .onFailure { e -> patch(g.key) { it.copy(error = poErr(e, "Could not save purchase order.")) } }
            } finally {
                patch(g.key) { it.copy(saving = false) }
            }
        }
    }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(5.dp)) {
        PoHeader(
            "Make PO · ${groups.size} distributor(s)",
            "${groups.sumOf { it.products.size }} product(s) grouped by last distributor",
            onBack = { auto.groups = null },
        )
        toast?.let { PoToast(it, onDismissToast) }
        Surface(color = Color(0xFFE0F2FE), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
            Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.Info, null, Modifier.size(16.dp), tint = Color(0xFF0369A1))
                Spacer(Modifier.width(6.dp))
                Text(
                    "One PO per distributor. Confirm the distributor for each group and save it.",
                    style = MaterialTheme.typography.labelMedium,
                    color = Color(0xFF075985),
                )
            }
        }
        LazyVerticalGrid(
            columns = GridCells.Adaptive(minSize = 340.dp),
            modifier = Modifier.weight(1f).fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(6.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            items(groups, key = { it.key }) { g ->
                PoGroupCard(
                    g = g,
                    distLoading = distLoading && distributors.isEmpty(),
                    onPickDistributor = { sheetFor = g.key },
                    onSave = { saveGroup(g) },
                )
            }
            item(span = { GridItemSpan(maxLineSpan) }) { Spacer(Modifier.height(12.dp)) }
        }
    }

    sheetFor?.let { key ->
        val g = groups.firstOrNull { it.key == key }
        PoDistributorSheet(
            vm = vm,
            distributors = distributors,
            loading = distLoading,
            selectedId = g?.distId,
            hint = g?.takeIf { it.distId == null }?.hint,
            onPick = { d ->
                patch(key) { it.copy(distId = d.id, distName = d.name, error = null) }
                sheetFor = null
            },
            onCreatedName = { name ->
                sheetFor = null
                onDistributorsReload(name) { d ->
                    patch(key) { it.copy(distId = d.id, distName = d.name, error = null) }
                }
            },
            onDismiss = { sheetFor = null },
        )
    }
}

@Composable
private fun PoGroupCard(g: AutoGroup, distLoading: Boolean, onPickDistributor: () -> Unit, onSave: () -> Unit) {
    val totalQty = poR2(g.products.sumOf { it.reqQty })
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = Color.White,
        border = BorderStroke(1.dp, if (g.error != null) MaterialTheme.colorScheme.error.copy(alpha = 0.6f) else PharmaBorder),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            PPickField(
                label = "Distributor *",
                value = if (g.distId != null) g.distName else "",
                placeholder = if (distLoading) "Loading distributors…" else "Select distributor",
                onClick = onPickDistributor,
                modifier = Modifier.fillMaxWidth(),
            )
            if (g.distId == null && g.hint.isNotBlank()) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.Info, null, Modifier.size(13.dp), tint = PoAmberText)
                    Spacer(Modifier.width(4.dp))
                    Text(
                        "Last purchased from “${g.hint}” — not in Distributor Master",
                        style = MaterialTheme.typography.labelSmall,
                        color = PoAmberText,
                    )
                }
            }
            Text(
                "${g.products.size} product(s) · Total qty ${poQty(totalQty)}",
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = PharmaTeal,
            )
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(6.dp)).border(1.dp, PharmaBorder, RoundedCornerShape(6.dp))) {
                Row(Modifier.fillMaxWidth().background(BillGridHeaderBg), verticalAlignment = Alignment.CenterVertically) {
                    BillGridTh("#", 24.dp)
                    Text("PRODUCT", Modifier.weight(1f).padding(horizontal = 2.dp, vertical = 6.dp), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = Color(0xFF64748B))
                    BillGridTh("REQ QTY", 60.dp, alignEnd = true)
                }
                g.products.forEachIndexed { i, p ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                        BillGridCellText("${i + 1}", 24.dp, color = PharmaMuted)
                        Column(Modifier.weight(1f).padding(horizontal = 2.dp)) {
                            Text(p.name, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                            val meta = listOf(p.pack, if (p.mrp > 0) "MRP ${formatAmount2(p.mrp)}" else "", p.category)
                                .filter { it.isNotBlank() }.joinToString(" · ")
                            if (meta.isNotBlank()) Text(meta, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        BillGridCellText(poQty(p.reqQty), 60.dp, alignEnd = true, fontWeight = FontWeight.SemiBold)
                    }
                    if (i < g.products.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.45f))
                }
            }
            g.error?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.error) }
            PharmaPrimaryButton(
                if (g.saving) "Saving…" else "Save PO",
                modifier = Modifier.fillMaxWidth(),
                enabled = !g.saving,
                onClick = onSave,
            )
        }
    }
}
