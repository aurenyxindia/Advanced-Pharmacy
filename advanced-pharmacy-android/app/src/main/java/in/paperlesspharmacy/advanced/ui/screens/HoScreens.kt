package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private enum class HoTab(val label: String) {
    Performance("Performance"),
    Portfolio("Portfolio"),
    Transfer("Stock Transfer"),
    Branches("Branches"),
}

private data class HoTransferLine(
    val bindingId: Int,
    val productName: String,
    val availableQty: Double,
    val quantity: Double,
)

@Composable
fun HoModuleScreen(vm: AppViewModel, onBack: () -> Unit) {
    val repo = remember { vm.ho() }
    val scope = rememberCoroutineScope()
    var tab by rememberSaveable { mutableStateOf(HoTab.Performance.name) }
    val current = HoTab.entries.firstOrNull { it.name == tab } ?: HoTab.Performance

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PoHeader("Head Office", "Consolidated multi-branch control", onBack)
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            items(HoTab.entries.toList()) { t ->
                PoChip(t.label, current == t) { tab = t.name }
            }
        }
        Box(Modifier.weight(1f)) {
            when (current) {
                HoTab.Performance -> HoPerformanceTab(repo)
                HoTab.Portfolio -> HoPortfolioTab(repo)
                HoTab.Transfer -> HoTransferTab(repo)
                HoTab.Branches -> MasterCrudScreen(vm, "branches", showBack = false, onBack = { tab = HoTab.Performance.name })
            }
        }
    }
}

@Composable
private fun HoPerformanceTab(repo: `in`.paperlesspharmacy.advanced.data.transaction.HoRepository) {
    val scope = rememberCoroutineScope()
    var branches by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var totals by remember { mutableStateOf(JsonObject()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }

    fun load() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.branchPerformance() } }
                .onSuccess { data ->
                    branches = data.arr("branches").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
                    totals = data.obj("totals")
                }
                .onFailure { error = careErr(it, "Could not load branch performance.") }
            loading = false
        }
    }
    LaunchedEffect(Unit) { load() }

    val shown = remember(branches, search) {
        val q = search.trim().lowercase()
        if (q.isEmpty()) branches else branches.filter {
            it.str("name").lowercase().contains(q) || it.str("code").lowercase().contains(q)
        }
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        error?.let { ErrorBanner(it) { error = null } }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it },
                placeholder = "Search branch…",
                modifier = Modifier.weight(1f),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
            IconButton(onClick = { load() }, Modifier.size(34.dp)) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
        }
        CareKpiGrid(
            listOf(
                CareKpiItem("Total Sales", careMoney(totals.amount("sales")), PharmaTeal, Icons.Default.TrendingUp),
                CareKpiItem("Total Purchase", careMoney(totals.amount("purchase")), PharmaSlate, Icons.Default.ShoppingCart),
                CareKpiItem("Total Profit", careMoney(totals.amount("profit")), Color(0xFF15803D), Icons.Default.Savings),
                CareKpiItem("Stock Value", careMoney(totals.amount("stock_value")), Color(0xFF2563EB), Icons.Default.Inventory2),
            ),
        )
        when {
            loading -> Box(Modifier.weight(1f)) { LoadingBlock("Loading performance…") }
            shown.isEmpty() -> CareEmpty(Icons.Default.CorporateFare, "No branches found.", Modifier.weight(1f))
            else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(shown, key = { it.jInt("branch_id") }) { b ->
                    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        Text(b.str("name"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                        if (b.jInt("is_head_office") != 0 || b.bool("is_head_office")) {
                                            Spacer(Modifier.width(6.dp))
                                            CareBadge("HO", Color(0xFF0284C7))
                                        }
                                    }
                                    Text(b.str("code"), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                }
                                Text("${b.jInt("skus")} SKUs", style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                            }
                            Row {
                                CareInfo("Sales", careMoney(b.amount("sales")), Modifier.weight(1f))
                                CareInfo("Purchase", careMoney(b.amount("purchase")), Modifier.weight(1f))
                                CareInfo("Profit", careMoney(b.amount("profit")), Modifier.weight(1f), valueColor = Color(0xFF15803D))
                                CareInfo("Stock", careMoney(b.amount("stock_value")), Modifier.weight(1f))
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun HoPortfolioTab(repo: `in`.paperlesspharmacy.advanced.data.transaction.HoRepository) {
    val scope = rememberCoroutineScope()
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }

    fun load() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.portfolio() } }
                .onSuccess { rows = it }
                .onFailure { error = careErr(it, "Could not load portfolio.") }
            loading = false
        }
    }
    LaunchedEffect(Unit) { load() }

    val shown = remember(rows, search) {
        val q = search.trim().lowercase()
        if (q.isEmpty()) rows else rows.filter {
            it.str("product_name").lowercase().contains(q) || it.str("schedule_category").lowercase().contains(q)
        }
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        error?.let { ErrorBanner(it) { error = null } }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it },
                placeholder = "Search product / schedule…",
                modifier = Modifier.weight(1f),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
            IconButton(onClick = { load() }, Modifier.size(34.dp)) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
        }
        if (rows.isNotEmpty()) {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                SalesLogKpiChip("Products", rows.size.toString(), Modifier.weight(1f))
                SalesLogKpiChip("Total stock", rows.sumOf { it.amount("total_stock") }.let { if (it % 1.0 == 0.0) it.toLong().toString() else rptMoney(it) }, Modifier.weight(1.2f))
                SalesLogKpiChip("Multi-branch", rows.count { it.jInt("branch_count") > 1 }.toString(), Modifier.weight(1.2f))
            }
        }
        when {
            loading -> Box(Modifier.weight(1f)) { LoadingBlock("Loading portfolio…") }
            shown.isEmpty() -> CareEmpty(Icons.Default.Inventory, "No products in the portfolio yet.", Modifier.weight(1f))
            else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(shown, key = { it.jInt("master_product_id") }) { p ->
                    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                        Row(Modifier.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(p.str("product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    if (p.str("schedule_category").isNotBlank()) CareBadge(p.str("schedule_category"), Color(0xFF475569))
                                    Text("${p.jInt("branch_count")} branch${if (p.jInt("branch_count") == 1) "" else "es"}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                }
                            }
                            Column(horizontalAlignment = Alignment.End) {
                                Text("Stock", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                Text(
                                    p.amount("total_stock").let { if (it % 1.0 == 0.0) it.toLong().toString() else rptMoney(it) },
                                    style = MaterialTheme.typography.bodyMedium,
                                    fontWeight = FontWeight.Bold,
                                    color = PharmaTealDark,
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun HoTransferTab(repo: `in`.paperlesspharmacy.advanced.data.transaction.HoRepository) {
    val scope = rememberCoroutineScope()
    var branches by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var fromId by remember { mutableStateOf<Int?>(null) }
    var toId by remember { mutableStateOf<Int?>(null) }
    var stock by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var stockLoading by remember { mutableStateOf(false) }
    var lines by remember { mutableStateOf<List<HoTransferLine>>(emptyList()) }
    var transfers by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    var picker by remember { mutableStateOf(false) }
    var logId by remember { mutableStateOf<Int?>(null) }
    var logRow by remember { mutableStateOf<JsonObject?>(null) }
    var logLoading by remember { mutableStateOf(false) }

    fun branchLabel(id: Int?): String {
        if (id == null) return "— Select —"
        val b = branches.firstOrNull { it.jInt("id") == id } ?: return "Branch #$id"
        return "${b.str("code")} - ${b.str("name")}"
    }

    fun loadTransfers() {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.transfers() } }
                .onSuccess { transfers = it }
                .onFailure { error = careErr(it, "Could not load transfers.") }
        }
    }

    fun loadStock(branchId: Int) {
        stockLoading = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.branchStock(branchId) } }
                .onSuccess { stock = it }
                .onFailure { stock = emptyList(); error = careErr(it, "Could not load branch stock.") }
            stockLoading = false
        }
    }

    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { repo.branches() } }.onSuccess { branches = it }
        loadTransfers()
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }

        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("New stock transfer", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        POptionDropdown(
                            "From Branch",
                            fromId?.toString() ?: "",
                            listOf("") + branches.map { it.jInt("id").toString() },
                            { v ->
                                val id = v.toIntOrNull()
                                fromId = id
                                lines = emptyList()
                                stock = emptyList()
                                if (id != null) loadStock(id)
                            },
                            Modifier.weight(1f),
                            display = { v -> if (v.isBlank()) "— Select —" else branchLabel(v.toIntOrNull()) },
                        )
                        POptionDropdown(
                            "To Branch",
                            toId?.toString() ?: "",
                            listOf("") + branches.map { it.jInt("id").toString() },
                            { v -> toId = v.toIntOrNull() },
                            Modifier.weight(1f),
                            display = { v -> if (v.isBlank()) "— Select —" else branchLabel(v.toIntOrNull()) },
                        )
                    }
                    Text("Add product (stock from From Branch)", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    PharmaOutlinedButton(
                        when {
                            fromId == null -> "Select From Branch first…"
                            stockLoading -> "Loading stock…"
                            else -> "Select product with stock…"
                        },
                        Modifier.fillMaxWidth(),
                        enabled = fromId != null && !stockLoading,
                    ) { picker = true }

                    if (lines.isEmpty()) {
                        Text("No products added.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted, modifier = Modifier.padding(vertical = 8.dp))
                    } else {
                        lines.forEachIndexed { i, l ->
                            key(l.bindingId) {
                                Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
                                    Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Column(Modifier.weight(1f)) {
                                            Text(l.productName, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                            Text("Avail ${if (l.availableQty % 1.0 == 0.0) l.availableQty.toLong() else rptMoney(l.availableQty)}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                        }
                                        SalesFormField(
                                            "Qty",
                                            if (l.quantity % 1.0 == 0.0) l.quantity.toLong().toString() else l.quantity.toString(),
                                            { v ->
                                                val q = v.toDoubleOrNull() ?: 0.0
                                                val capped = q.coerceIn(0.0, l.availableQty)
                                                lines = lines.mapIndexed { idx, line -> if (idx == i) line.copy(quantity = capped) else line }
                                            },
                                            Modifier.width(88.dp),
                                            keyboardType = KeyboardType.Decimal,
                                        )
                                        IconButton(onClick = { lines = lines.filterIndexed { idx, _ -> idx != i } }, Modifier.size(32.dp)) {
                                            Icon(Icons.Default.Close, "Remove", Modifier.size(18.dp), tint = Color(0xFFDC2626))
                                        }
                                    }
                                }
                            }
                        }
                    }
                    PharmaPrimaryButton(if (saving) "Transferring…" else "Transfer Stock", Modifier.fillMaxWidth(), enabled = !saving) {
                        error = null
                        when {
                            fromId == null || toId == null -> error = "Select both branches."
                            fromId == toId -> error = "Branches must differ."
                            lines.isEmpty() -> error = "Add at least one product."
                            lines.any { it.quantity <= 0 } -> error = "Enter a valid qty for all products."
                            lines.any { it.quantity > it.availableQty } -> {
                                val bad = lines.first { it.quantity > it.availableQty }
                                error = "${bad.productName}: qty exceeds available stock (${bad.availableQty})."
                            }
                            else -> {
                                saving = true
                                scope.launch {
                                    try {
                                        runCatching {
                                            withContext(Dispatchers.IO) {
                                                repo.transfer(fromId!!, toId!!, lines.map { it.bindingId to it.quantity })
                                            }
                                        }.onSuccess {
                                            toast = "Stock transferred successfully."
                                            lines = emptyList()
                                            loadTransfers()
                                            fromId?.let { loadStock(it) }
                                        }.onFailure { error = careErr(it, "Transfer failed.") }
                                    } finally {
                                        saving = false
                                    }
                                }
                            }
                        }
                    }
                }
            }

            Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                Column {
                    Text("Recent Transfers", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate, modifier = Modifier.padding(10.dp))
                    HorizontalDivider(color = PharmaBorder)
                    if (transfers.isEmpty()) {
                        CareEmpty(Icons.Default.SwapHoriz, "No transfers yet.")
                    } else {
                        transfers.forEach { t ->
                            Row(
                                Modifier
                                    .fillMaxWidth()
                                    .clickable {
                                        logId = t.jInt("id")
                                        logLoading = true
                                        logRow = null
                                        scope.launch {
                                            runCatching { withContext(Dispatchers.IO) { repo.transferLog(t.jInt("id")) } }
                                                .onSuccess { logRow = it }
                                                .onFailure { error = careErr(it, "Could not load transfer log."); logId = null }
                                            logLoading = false
                                        }
                                    }
                                    .padding(horizontal = 10.dp, vertical = 8.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(Modifier.weight(1f)) {
                                    Text(t.str("transfer_no"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                                    Text(careDate(t.str("transfer_date")), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                }
                                CareInfo("Items", t.jInt("item_count").toString(), Modifier.width(56.dp))
                                Text(careMoney(t.amount("total_value")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                Icon(Icons.Default.Visibility, "View log", Modifier.size(18.dp).padding(start = 6.dp), tint = PharmaTeal)
                            }
                            HorizontalDivider(color = PharmaBorder)
                        }
                    }
                }
            }
        }
    }

    if (picker) {
        RnPickerLike(
            title = "Select product with stock",
            options = stock.map {
                it.jInt("binding_id").toString() to buildString {
                    append(it.str("product_name"))
                    val mfr = it.str("manufacturer_short")
                    val pack = it.str("pack_size")
                    if (mfr.isNotBlank() || pack.isNotBlank()) {
                        append("\n")
                        if (mfr.isNotBlank()) append(mfr)
                        if (mfr.isNotBlank() && pack.isNotBlank()) append(" · ")
                        if (pack.isNotBlank()) append(pack)
                    }
                    append(" · Stock ${it.amount("available_qty").let { q -> if (q % 1.0 == 0.0) q.toLong() else rptMoney(q) }}")
                }
            },
            emptyText = if (stockLoading) "Loading…" else "No stock available in this branch",
            onPick = { idStr ->
                picker = false
                val p = stock.firstOrNull { it.jInt("binding_id").toString() == idStr } ?: return@RnPickerLike
                val bid = p.jInt("binding_id")
                if (lines.any { it.bindingId == bid }) return@RnPickerLike
                lines = lines + HoTransferLine(bid, p.str("product_name"), p.amount("available_qty"), 1.0)
            },
            onDismiss = { picker = false },
        )
    }

    if (logId != null) {
        ModalBottomSheet(
            onDismissRequest = { logId = null; logRow = null },
            sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
            containerColor = Color.White,
        ) {
            Column(
                Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 14.dp)
                    .padding(bottom = 18.dp)
                    .verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text("Transfer Log", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = PharmaSlate)
                when {
                    logLoading -> LoadingBlock("Loading…")
                    logRow != null -> {
                        val t = logRow!!
                        Row {
                            CareInfo("Transfer No", t.str("transfer_no"), Modifier.weight(1f))
                            CareInfo("Date", careDate(t.str("transfer_date")), Modifier.weight(1f))
                            CareInfo("Value", careMoney(t.amount("total_value")), Modifier.weight(1f))
                        }
                        Row {
                            CareInfo("From", branchLabel(t.jInt("from_branch_id")), Modifier.weight(1f))
                            CareInfo("To", branchLabel(t.jInt("to_branch_id")), Modifier.weight(1f))
                        }
                        if (t.str("notes").isNotBlank()) CareInfo("Notes", t.str("notes"))
                        val items = t.arr("items").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
                        Text("Items (${items.size})", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        items.forEach { it ->
                            Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
                                Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                    Text(it.str("product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                    Row {
                                        CareInfo("Batch", it.str("batch_no").ifBlank { "—" }, Modifier.weight(1f))
                                        CareInfo("Qty", it.amount("quantity").let { q -> if (q % 1.0 == 0.0) q.toLong().toString() else rptMoney(q) }, Modifier.weight(0.8f))
                                        CareInfo("Cost/Unit", rptMoney(it.amount("cost_per_unit")), Modifier.weight(1f))
                                        CareInfo("Value", careMoney(it.amount("line_value")), Modifier.weight(1f))
                                    }
                                }
                            }
                        }
                        if (items.isEmpty()) Text("No line items.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                    }
                }
            }
        }
    }
}

/** Compact product picker reused from Return Note pattern (local to avoid coupling). */
@Composable
private fun RnPickerLike(
    title: String,
    options: List<Pair<String, String>>,
    emptyText: String,
    onPick: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    var q by remember { mutableStateOf("") }
    val shown = remember(q, options) {
        val t = q.trim().lowercase()
        (if (t.isEmpty()) options else options.filter { it.second.lowercase().contains(t) }).take(80)
    }
    Dialog(onDismissRequest = onDismiss) {
        Surface(shape = RoundedCornerShape(12.dp), color = Color.White, modifier = Modifier.fillMaxWidth().heightIn(max = 560.dp)) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                SalesDenseSearchField(
                    value = q,
                    onValueChange = { q = it },
                    placeholder = "Search product…",
                    modifier = Modifier.fillMaxWidth(),
                    leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
                )
                when {
                    shown.isEmpty() -> Text(if (options.isEmpty()) emptyText else "No products match", style = MaterialTheme.typography.bodySmall, color = PharmaMuted, modifier = Modifier.padding(vertical = 16.dp))
                    else -> LazyColumn(Modifier.weight(1f, fill = false)) {
                        items(shown) { (v, l) ->
                            Column(
                                Modifier
                                    .fillMaxWidth()
                                    .clickable { onPick(v) }
                                    .padding(horizontal = 6.dp, vertical = 9.dp),
                            ) {
                                val parts = l.split("\n", limit = 2)
                                Text(parts[0], style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                if (parts.size > 1) Text(parts[1], style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            }
                            HorizontalDivider(color = PharmaBorder)
                        }
                    }
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                    TextButton(onClick = onDismiss) { Text("Close") }
                }
            }
        }
    }
}
