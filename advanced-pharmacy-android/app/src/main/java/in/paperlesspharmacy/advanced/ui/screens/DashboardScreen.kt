package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import `in`.paperlesspharmacy.advanced.ui.util.formatInr
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlin.math.max

private val DashTeal = PharmaTeal
private val DashIndigo = Color(0xFF4F46E5)
private val DashBlue = Color(0xFF0284C7)
private val DashGreen = Color(0xFF15803D)
private val DashAmber = Color(0xFFD97706)
private val DashRed = Color(0xFFDC2626)
private val DashViolet = Color(0xFF7C3AED)
private val DashRose = Color(0xFFF43F5E)
private val Palette = listOf(DashTeal, DashIndigo, DashAmber, DashBlue, DashRed, DashGreen, DashViolet)

private fun moneyColor(v: Double): Color = when {
    v < 0 -> DashRed
    v > 0 -> DashGreen
    else -> PharmaMuted
}

private fun trendLabel(iso: String): String {
    val m = Regex("""^(\d{4})-(\d{2})-(\d{2})""").find(iso.trim()) ?: return iso.take(5)
    return "${m.groupValues[3]}-${m.groupValues[2]}"
}

@Composable
fun DashboardScreen(
    vm: AppViewModel,
    onOpenSales: () -> Unit,
    onOpenReports: () -> Unit = {},
    onOpenPurchaseOrder: () -> Unit = {},
    onOpenOpeningStock: () -> Unit = {},
    /** Opens Opening Stock → Minimum Inventory tab (Dashboard Set Min / Min Stock). */
    onOpenMinInventory: () -> Unit = onOpenOpeningStock,
    onOpenShortStockReport: () -> Unit = {},
) {
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var summary by remember { mutableStateOf<JsonObject?>(null) }
    var miRows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var miTotal by remember { mutableIntStateOf(0) }
    var miMonths by remember { mutableIntStateOf(4) }
    var miLoading by remember { mutableStateOf(true) }
    var showAllShort by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val user by vm.user.collectAsState()
    val stock = remember { vm.stock() }

    val isHo = user?.role == "ho_admin" || user?.scope == "ho"
    val canSale = vm.canEdit("SALES") || vm.hasFullAccess()
    val canPo = vm.canEdit("PURCHASE_ORDER") || vm.hasFullAccess()
    val canReports = vm.canView("ALL_REPORTS") || vm.hasFullAccess()
    val canMinEdit = vm.canEdit("OPENING_STOCK") || vm.hasFullAccess()

    fun loadMi() {
        scope.launch {
            miLoading = true
            runCatching { withContext(Dispatchers.IO) { stock.minAlerts(5) } }
                .onSuccess { mi ->
                    miTotal = mi.jInt("total")
                    miMonths = mi.jInt("sales_months").takeIf { it > 0 } ?: 4
                    miRows = mi.arr("products").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
                }
                .onFailure { miRows = emptyList(); miTotal = 0 }
            miLoading = false
        }
    }

    fun load() {
        scope.launch {
            loading = true
            error = null
            runCatching { withContext(Dispatchers.IO) { vm.api.get("dashboard/summary").obj("data") } }
                .onSuccess { summary = it }
                .onFailure { error = (it as? ApiException)?.message ?: "Unable to load dashboard data." }
            loading = false
        }
        loadMi()
    }

    LaunchedEffect(Unit) { load() }

    if (loading && summary == null) {
        LoadingBlock("Loading dashboard…")
        return
    }

    val data = summary
    val k = data?.obj("kpis") ?: JsonObject()
    val buckets = data?.obj("expiry_buckets") ?: JsonObject()
    val expiryLoss = data?.obj("expiry_loss") ?: JsonObject()
    val profit = data?.obj("profit_analytics") ?: JsonObject()
    val stores = data?.arr("store_analytics")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }.orEmpty()
    val salesTrend = data?.arr("sales_trend")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }.orEmpty()
    val purchaseTrend = data?.arr("purchase_trend")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }.orEmpty()
    val distributors = data?.arr("distributor_purchase")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }.orEmpty()
    val categories = data?.arr("category_split")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }.orEmpty()
    val topProducts = data?.arr("top_products")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }.orEmpty()
    val recent = data?.arr("recent_sales")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }.orEmpty()

    LazyColumn(
        Modifier.fillMaxSize().padding(CompactScreenPadding),
        verticalArrangement = Arrangement.spacedBy(10.dp),
        contentPadding = PaddingValues(bottom = 20.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Dashboard", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold, color = PharmaSlate)
                    Text(
                        if (isHo) "Consolidated overview across all branches"
                        else "Your branch performance at a glance",
                        style = MaterialTheme.typography.bodySmall,
                        color = PharmaMuted,
                    )
                    if (!user?.name.isNullOrBlank()) {
                        Text(
                            "${user?.name} · ${user?.role?.replace('_', ' ')?.replaceFirstChar { it.uppercase() }.orEmpty()}",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                        )
                    }
                }
                IconButton(onClick = { load() }) {
                    Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal)
                }
            }
        }

        // Quick actions
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                if (canSale) {
                    DashAction("New Sale", Icons.Default.PointOfSale, DashTeal, Modifier.weight(1f), onOpenSales)
                }
                if (canReports) {
                    DashAction("Reports", Icons.Default.BarChart, DashIndigo, Modifier.weight(1f), onOpenReports)
                }
                DashAction("Min Stock", Icons.Default.Tune, DashAmber, Modifier.weight(1f), onOpenMinInventory)
                if (canPo) {
                    DashAction("Auto PO", Icons.Default.Assignment, DashBlue, Modifier.weight(1f), onOpenPurchaseOrder)
                }
            }
        }

        if (error != null) {
            item { ErrorBanner(error!!) { error = null; load() } }
        }

        if (data != null) {
            // Financial overview
            item {
                DashSection("Sales, Purchase & Financial", Icons.Default.TrendingUp, DashTeal) {
                    DashTileGrid(
                        listOf(
                            Triple("Today's Sales", formatInr(k.amount("today_sales")), DashTeal),
                            Triple("Month Sales", formatInr(k.amount("month_sales")), DashIndigo),
                            Triple("Month Purchase", formatInr(k.amount("month_purchase")), DashBlue),
                            Triple("Month Profit", formatInr(k.amount("month_profit")), DashGreen),
                            Triple("Today's Outstanding", formatInr(k.amount("outstanding_today")), DashAmber),
                            Triple("Total Outstanding", formatInr(k.amount("outstanding_total")), DashRed),
                        ),
                    )
                }
            }

            // Expiry management
            item {
                DashSection(
                    "Expiry Management",
                    Icons.Default.HourglassBottom,
                    DashRose,
                    trailing = if (canReports) ({
                        TextButton(onClick = onOpenReports) {
                            Text("View More", style = MaterialTheme.typography.labelSmall, color = Color.White)
                        }
                    }) else null,
                ) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        DashStat("Expired", k.jInt("expired_count").toString(), DashRed, Modifier.weight(1f))
                        DashStat("≤ 30 days", buckets.jInt("within_30").toString(), DashAmber, Modifier.weight(1f))
                        DashStat("≤ 90 days", k.jInt("expiring_count").toString(), DashBlue, Modifier.weight(1f))
                    }
                    Spacer(Modifier.height(8.dp))
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        MetaChip(Icons.Default.Inventory2, "Products", k.jInt("product_count").toString())
                        MetaChip(Icons.Default.Warning, "Low stock", k.jInt("low_stock_count").toString())
                        MetaChip(Icons.Default.People, "Customers", k.jInt("customer_count").toString())
                    }
                }
            }

            // Minimum inventory
            item {
                DashSection(
                    "Minimum Inventory Alert" + if (miTotal > 0) " · $miTotal" else "",
                    Icons.Default.NotificationsActive,
                    DashAmber,
                    trailing = {
                        Row {
                            TextButton(onClick = onOpenMinInventory) {
                                Text("Set Min", style = MaterialTheme.typography.labelSmall, color = Color.White)
                            }
                            if (canPo && miTotal > 0) {
                                TextButton(onClick = onOpenPurchaseOrder) {
                                    Text("Auto PO", style = MaterialTheme.typography.labelSmall, color = Color.White)
                                }
                            }
                        }
                    },
                ) {
                    when {
                        miLoading -> Text("Loading alerts…", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                        miRows.isEmpty() -> Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Default.CheckCircle, null, Modifier.size(16.dp), tint = DashGreen)
                            Spacer(Modifier.width(6.dp))
                            Text("All products are above minimum inventory.", style = MaterialTheme.typography.bodySmall, color = DashGreen)
                        }
                        else -> {
                            if (miTotal > 0) {
                                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.padding(bottom = 6.dp)) {
                                    PharmaOutlinedButton("View All ($miTotal)", Modifier.heightIn(max = 32.dp)) { showAllShort = true }
                                    if (canReports) {
                                        PharmaOutlinedButton("Report", Modifier.heightIn(max = 32.dp), onClick = onOpenShortStockReport)
                                    }
                                }
                            }
                            miRows.forEachIndexed { i, row ->
                                MiRow(i + 1, row, miMonths)
                                if (i < miRows.lastIndex) HorizontalDivider(color = PharmaBorder, modifier = Modifier.padding(vertical = 2.dp))
                            }
                            if (miTotal > miRows.size) {
                                Text(
                                    "Showing top ${miRows.size} most-sold of $miTotal short products.",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaMuted,
                                    modifier = Modifier.padding(top = 4.dp),
                                )
                            }
                        }
                    }
                }
            }

            // Expired stock loss
            if (expiryLoss.size() > 0) {
                item {
                    DashSection("Expired Stock Loss", Icons.Default.HourglassDisabled, DashRose) {
                        Text("Money lost because medicines expired", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        Spacer(Modifier.height(6.dp))
                        listOf(
                            "last_week" to "Last week",
                            "last_month" to "Last month",
                            "mtd" to "Month till date",
                        ).forEach { (key, label) ->
                            val p = expiryLoss.obj(key)
                            LossPeriodCard(label, p)
                            Spacer(Modifier.height(6.dp))
                        }
                        val all = expiryLoss.obj("all_expired")
                        if (all.size() > 0) {
                            Surface(shape = RoundedCornerShape(8.dp), color = DashRose.copy(alpha = 0.08f), modifier = Modifier.fillMaxWidth()) {
                                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Text("All expired stock now", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                    Row {
                                        CareInfo("Units", all.jInt("qty").toString(), Modifier.weight(1f))
                                        CareInfo("Cost lost", formatInr(all.amount("expiry_loss")), Modifier.weight(1f), valueColor = DashRed)
                                        CareInfo("MRP missed", formatInr(all.amount("revenue_loss")), Modifier.weight(1f), valueColor = DashRed)
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Profit & discount
            if (profit.size() > 0) {
                item {
                    val month = profit.obj("month")
                    val three = profit.obj("three_months")
                    val channels = profit.arr("by_channel").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
                    val cats = profit.arr("by_product_category").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
                    val monthly = profit.arr("monthly").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
                    DashSection("Profit & Discount", Icons.Default.Payments, DashGreen) {
                        Text("Discount given = profit you did not keep", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        Spacer(Modifier.height(6.dp))
                        Surface(shape = RoundedCornerShape(8.dp), color = DashGreen.copy(alpha = 0.06f), modifier = Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                Text("This month at a glance", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                BigMoney("Profit you earned", month.amount("profit"))
                                BigMoney("If no discount", month.amount("expected_profit"))
                                BigMoney("Lost on discount", month.amount("missed_profit"), forceBad = true)
                                Row {
                                    CareInfo("This month", formatInr(month.amount("profit")), Modifier.weight(1f), valueColor = moneyColor(month.amount("profit")))
                                    CareInfo("Last 3 months", formatInr(three.amount("profit")), Modifier.weight(1f), valueColor = moneyColor(three.amount("profit")))
                                }
                            }
                        }
                        if (channels.isNotEmpty()) {
                            Spacer(Modifier.height(8.dp))
                            Text("OTC vs Prescription", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                            channels.forEach { c ->
                                val title = if (c.str("channel") == "OTC") "OTC (no Rx)" else "Prescription"
                                Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth().padding(top = 4.dp)) {
                                    Column(Modifier.padding(8.dp)) {
                                        Text(title, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                        Row {
                                            CareInfo("Profit", formatInr(c.amount("profit")), Modifier.weight(1f), valueColor = moneyColor(c.amount("profit")))
                                            CareInfo("Discount loss", formatInr(c.amount("discount_loss")), Modifier.weight(1f), valueColor = DashRed)
                                        }
                                    }
                                }
                            }
                        }
                        if (cats.isNotEmpty()) {
                            Spacer(Modifier.height(8.dp))
                            Text("By product category", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                            cats.take(8).forEach { c ->
                                Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Text(c.str("category"), Modifier.weight(1.4f), style = MaterialTheme.typography.bodySmall, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(formatInr(c.amount("profit")), Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = moneyColor(c.amount("profit")))
                                    Text(formatInr(c.amount("discount_loss")), Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = DashRed)
                                }
                            }
                        }
                        if (monthly.isNotEmpty()) {
                            Spacer(Modifier.height(8.dp))
                            Text("Monthly profit vs discount loss", style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                            DualBarChart(
                                labels = monthly.map { it.str("label").ifBlank { it.str("ym") } },
                                a = monthly.map { it.amount("profit") },
                                b = monthly.map { it.amount("discount_loss") },
                                colorA = DashGreen,
                                colorB = DashRed,
                                modifier = Modifier.fillMaxWidth().height(140.dp).padding(top = 4.dp),
                            )
                        }
                    }
                }
            }

            // HO store analytics
            if (isHo && stores.isNotEmpty()) {
                item {
                    DashSection("Each store — profit & expiry", Icons.Default.Store, DashIndigo) {
                        stores.forEach { s ->
                            Surface(shape = RoundedCornerShape(8.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth().padding(bottom = 6.dp)) {
                                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        Text(s.str("name"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaSlate, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                                        CareBadge(s.str("code"), DashIndigo)
                                    }
                                    Row {
                                        CareInfo("Month profit", formatInr(s.amount("month_profit")), Modifier.weight(1f), valueColor = moneyColor(s.amount("month_profit")))
                                        CareInfo("Discount lost", formatInr(s.amount("missed_profit")), Modifier.weight(1f), valueColor = DashRed)
                                    }
                                    Row {
                                        CareInfo("Expiry loss", formatInr(s.amount("expiry_loss")), Modifier.weight(1f), valueColor = DashRed)
                                        CareInfo("Month sales", formatInr(s.amount("month_sales")), Modifier.weight(1f))
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Sales & purchase trend
            item {
                DashSection("Sales & Purchase Trend (14 days)", Icons.Default.ShowChart, DashBlue) {
                    if (salesTrend.isEmpty() && purchaseTrend.isEmpty()) {
                        EmptyHint(Icons.Default.ShowChart, "Trend appears once sales/purchases begin.")
                    } else {
                        val labels = (salesTrend.ifEmpty { purchaseTrend }).map { trendLabel(it.str("date")) }
                        val salesMap = salesTrend.associate { it.str("date").take(10) to it.amount("total") }
                        val purchMap = purchaseTrend.associate { it.str("date").take(10) to it.amount("total") }
                        val dates = (salesTrend.ifEmpty { purchaseTrend }).map { it.str("date").take(10) }
                        DualBarChart(
                            labels = labels,
                            a = dates.map { salesMap[it] ?: 0.0 },
                            b = dates.map { purchMap[it] ?: 0.0 },
                            colorA = DashTeal,
                            colorB = DashIndigo,
                            modifier = Modifier.fillMaxWidth().height(160.dp),
                        )
                        Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            LegendDot(DashTeal, "Sales")
                            LegendDot(DashIndigo, "Purchase")
                        }
                    }
                }
            }

            // Distributor-wise purchase
            item {
                DashSection("Distributor-Wise Purchase", Icons.Default.LocalShipping, DashTeal) {
                    if (distributors.isEmpty()) EmptyHint(Icons.Default.LocalShipping, "No purchases recorded yet.")
                    else {
                        val maxV = distributors.maxOf { it.amount("total") }.coerceAtLeast(1.0)
                        distributors.take(8).forEachIndexed { i, d ->
                            HBarRow(
                                label = d.str("supplier"),
                                value = d.amount("total"),
                                max = maxV,
                                color = Palette[i % Palette.size],
                                valueText = formatInr(d.amount("total")),
                            )
                        }
                    }
                }
            }

            // Categories + top products side by side on wide, stacked on phone
            item {
                DashSection("Product Categories", Icons.Default.PieChart, DashViolet) {
                    if (categories.isEmpty()) EmptyHint(Icons.Default.PieChart, "No products bound yet.")
                    else {
                        val total = categories.sumOf { it.jInt("cnt").toDouble() }.coerceAtLeast(1.0)
                        categories.take(8).forEachIndexed { i, c ->
                            HBarRow(
                                label = c.str("category"),
                                value = c.jInt("cnt").toDouble(),
                                max = total,
                                color = Palette[i % Palette.size],
                                valueText = c.jInt("cnt").toString(),
                            )
                        }
                    }
                }
            }

            item {
                DashSection("Top Selling Products", Icons.Default.EmojiEvents, DashIndigo) {
                    if (topProducts.isEmpty()) EmptyHint(Icons.Default.BarChart, "Sales data appears once billing begins.")
                    else {
                        val maxR = topProducts.maxOf { it.amount("revenue") }.coerceAtLeast(1.0)
                        topProducts.take(8).forEachIndexed { i, p ->
                            HBarRow(
                                label = p.str("product_name"),
                                value = p.amount("revenue"),
                                max = maxR,
                                color = DashIndigo,
                                valueText = formatInr(p.amount("revenue")),
                                sub = "Qty ${p.jInt("qty")}",
                            )
                        }
                    }
                }
            }

            item {
                DashSection("Recent Sales", Icons.Default.Receipt, DashTeal) {
                    if (recent.isEmpty()) EmptyHint(Icons.Default.Receipt, "No sales yet.")
                    else {
                        recent.take(10).forEachIndexed { i, s ->
                            Row(
                                Modifier.fillMaxWidth().padding(vertical = 5.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(Modifier.weight(1.4f)) {
                                    Text(
                                        s.str("invoice_no").ifBlank { s.str("order_no") },
                                        style = MaterialTheme.typography.bodySmall,
                                        fontWeight = FontWeight.SemiBold,
                                        color = PharmaTealDark,
                                        maxLines = 1,
                                        overflow = TextOverflow.Ellipsis,
                                    )
                                    Text(careDate(s.str("invoice_date")), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                }
                                Text(
                                    s.str("customer_name").ifBlank { "Walk-in" },
                                    Modifier.weight(1.2f),
                                    style = MaterialTheme.typography.bodySmall,
                                    color = PharmaSlate,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis,
                                )
                                Text(
                                    formatInr(s.amount("net_amount")),
                                    style = MaterialTheme.typography.bodySmall,
                                    fontWeight = FontWeight.Bold,
                                    color = PharmaSlate,
                                )
                            }
                            if (i < recent.size.coerceAtMost(10) - 1) HorizontalDivider(color = PharmaBorder)
                        }
                    }
                }
            }
        }
    }

    if (showAllShort) {
        ShortStockSheet(
            vm = vm,
            canEdit = canMinEdit,
            canGenerateOrder = canPo,
            onOpenMinInventory = { showAllShort = false; onOpenMinInventory() },
            onOpenPurchaseOrder = { showAllShort = false; onOpenPurchaseOrder() },
            onDismiss = { showAllShort = false; loadMi() },
        )
    }
}

// ───────────────────────── Short stock sheet ─────────────────────────

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ShortStockSheet(
    vm: AppViewModel,
    canEdit: Boolean,
    canGenerateOrder: Boolean,
    onOpenMinInventory: () -> Unit,
    onOpenPurchaseOrder: () -> Unit,
    onDismiss: () -> Unit,
) {
    val stock = remember { vm.stock() }
    val scope = rememberCoroutineScope()
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var drafts by remember { mutableStateOf<Map<Int, String>>(emptyMap()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }
    var shown by remember { mutableIntStateOf(50) }
    var months by remember { mutableIntStateOf(4) }
    var savingId by remember { mutableIntStateOf(0) }

    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { stock.minAlerts(0) } }
            .onSuccess { data ->
                months = data.jInt("sales_months").takeIf { it > 0 } ?: 4
                rows = data.arr("products").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
                drafts = rows.associate { it.jInt("id") to it.jInt("min_qty").toString() }
            }
            .onFailure { error = careErr(it, "Could not load short stock.") }
        loading = false
    }

    val filtered = remember(rows, search) {
        val q = search.trim().lowercase()
        if (q.isEmpty()) rows else rows.filter { it.str("product_name").lowercase().contains(q) }
    }
    val visible = filtered.take(shown)
    val outOfStock = rows.count { it.jInt("available_qty") <= 0 }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Color.White,
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .fillMaxHeight(0.92f)
                .padding(horizontal = 14.dp)
                .padding(bottom = 16.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Text("Short Stock — View All", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = PharmaSlate)
            Text(
                "${rows.size} products below minimum · $outOfStock out of stock · sold ${months}M",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
            error?.let { ErrorBanner(it) { error = null } }
            toast?.let { PoToast(it) { toast = null } }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PharmaOutlinedButton("Set Minimum", Modifier.weight(1f).heightIn(max = 34.dp), onClick = onOpenMinInventory)
                if (canGenerateOrder) {
                    PharmaPrimaryButton("Generate Order", Modifier.weight(1.2f).heightIn(max = 34.dp), onClick = onOpenPurchaseOrder)
                }
            }
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it; shown = 50 },
                placeholder = "Search product…",
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
            when {
                loading -> Box(Modifier.weight(1f), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(28.dp), strokeWidth = 2.dp) }
                visible.isEmpty() -> CareEmpty(Icons.Default.CheckCircle, "No short-stock products.", Modifier.weight(1f))
                else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(visible, key = { it.jInt("id") }) { r ->
                        val id = r.jInt("id")
                        Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder)) {
                            Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Text(r.str("product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                Row {
                                    CareInfo("Sold (${months}M)", r.jInt("sold_qty").toString(), Modifier.weight(1f))
                                    CareInfo("Available", r.jInt("available_qty").toString(), Modifier.weight(1f), valueColor = DashRed)
                                    CareInfo("Min", r.jInt("min_qty").toString(), Modifier.weight(1f))
                                }
                                if (canEdit) {
                                    Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                        SalesFormField(
                                            "New min",
                                            drafts[id] ?: "",
                                            { drafts = drafts + (id to it.filter { c -> c.isDigit() }.take(6)) },
                                            Modifier.weight(1f),
                                            keyboardType = KeyboardType.Number,
                                        )
                                        PharmaPrimaryButton(
                                            if (savingId == id) "…" else "Save",
                                            Modifier.heightIn(max = 34.dp),
                                            enabled = savingId == 0 && (drafts[id]?.toIntOrNull() ?: -1) != r.jInt("min_qty"),
                                        ) {
                                            val q = drafts[id]?.toIntOrNull() ?: return@PharmaPrimaryButton
                                            savingId = id
                                            scope.launch {
                                                runCatching { withContext(Dispatchers.IO) { stock.minSave(id, q) } }
                                                    .onSuccess {
                                                        toast = "Minimum updated."
                                                        rows = rows.map {
                                                            if (it.jInt("id") == id) {
                                                                it.deepCopy().apply { addProperty("min_qty", q); addProperty("custom_min", q) }
                                                            } else it
                                                        }
                                                    }
                                                    .onFailure { error = careErr(it, "Save failed.") }
                                                savingId = 0
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if (shown < filtered.size) {
                        item {
                            PharmaOutlinedButton("Show more", Modifier.fillMaxWidth()) { shown += 50 }
                        }
                    }
                }
            }
        }
    }
}

// ───────────────────────── UI atoms ─────────────────────────

@Composable
private fun DashAction(label: String, icon: ImageVector, color: Color, modifier: Modifier, onClick: () -> Unit) {
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = color.copy(alpha = 0.1f),
        border = BorderStroke(1.dp, color.copy(alpha = 0.35f)),
        modifier = modifier.clickable(onClick = onClick),
    ) {
        Column(
            Modifier.padding(vertical = 10.dp, horizontal = 4.dp).fillMaxWidth(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Icon(icon, null, Modifier.size(20.dp), tint = color)
            Spacer(Modifier.height(4.dp))
            Text(label, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = color, maxLines = 1)
        }
    }
}

@Composable
private fun DashSection(
    title: String,
    icon: ImageVector,
    accent: Color,
    trailing: (@Composable () -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    Surface(shape = RoundedCornerShape(12.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
        Column {
            Row(
                Modifier
                    .fillMaxWidth()
                    .background(accent)
                    .padding(horizontal = 10.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(icon, null, Modifier.size(16.dp), tint = Color.White)
                Spacer(Modifier.width(6.dp))
                Text(title, Modifier.weight(1f), style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = Color.White, maxLines = 1, overflow = TextOverflow.Ellipsis)
                trailing?.invoke()
            }
            Column(Modifier.padding(10.dp), content = content)
        }
    }
}

@Composable
private fun DashTileGrid(items: List<Triple<String, String, Color>>) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        items.chunked(2).forEach { row ->
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                row.forEach { (l, v, c) -> FinTile(l, v, c, Modifier.weight(1f)) }
                if (row.size == 1) Spacer(Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun DashStat(label: String, value: String, color: Color, modifier: Modifier) {
    Surface(shape = RoundedCornerShape(8.dp), color = color.copy(alpha = 0.08f), modifier = modifier) {
        Column(Modifier.padding(vertical = 10.dp).fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(value, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = color)
            Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        }
    }
}

@Composable
private fun MetaChip(icon: ImageVector, label: String, value: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, Modifier.size(14.dp), tint = PharmaTeal)
        Spacer(Modifier.width(3.dp))
        Text("$label $value", style = MaterialTheme.typography.labelSmall, color = PharmaSlate)
    }
}

@Composable
private fun MiRow(index: Int, row: JsonObject, months: Int) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("$index", Modifier.width(22.dp), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        Column(Modifier.weight(1f)) {
            Text(row.str("product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("Sold ${row.jInt("sold_qty")} (${months}M)", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text("Avail ${row.jInt("available_qty")}", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold, color = DashRed)
            Text("Min ${row.jInt("min_qty")}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        }
    }
}

@Composable
private fun LossPeriodCard(label: String, p: JsonObject) {
    Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(label, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            Row { CareInfo("Qty expired", p.jInt("qty").toString(), Modifier.weight(1f)); CareInfo("Batches", p.jInt("batches").toString(), Modifier.weight(1f)) }
            Row {
                CareInfo("Cost (paid)", formatInr(p.amount("purchase_value")), Modifier.weight(1f), valueColor = DashRed)
                CareInfo("MRP value", formatInr(p.amount("sale_value")), Modifier.weight(1f), valueColor = DashRed)
            }
            Row {
                Text("Total loss on expiry", Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                Text(formatInr(p.amount("expiry_loss")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = DashRed)
            }
        }
    }
}

@Composable
private fun BigMoney(label: String, value: Double, forceBad: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
        Text(formatInr(value), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = if (forceBad) DashRed else moneyColor(value))
    }
}

@Composable
private fun EmptyHint(icon: ImageVector, text: String) {
    Column(Modifier.fillMaxWidth().padding(vertical = 16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Icon(icon, null, Modifier.size(28.dp), tint = PharmaMuted)
        Spacer(Modifier.height(4.dp))
        Text(text, style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
    }
}

@Composable
private fun LegendDot(color: Color, label: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(8.dp).clip(RoundedCornerShape(2.dp)).background(color))
        Spacer(Modifier.width(4.dp))
        Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
    }
}

@Composable
private fun HBarRow(label: String, value: Double, max: Double, color: Color, valueText: String, sub: String = "") {
    Column(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(label, style = MaterialTheme.typography.bodySmall, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
            Text(valueText, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
        }
        Spacer(Modifier.height(3.dp))
        val frac = (value / max.coerceAtLeast(0.0001)).toFloat().coerceIn(0f, 1f)
        Canvas(Modifier.fillMaxWidth().height(8.dp)) {
            drawRoundRect(Color(0xFFE2E8F0), cornerRadius = CornerRadius(4.dp.toPx()), size = size)
            drawRoundRect(color, cornerRadius = CornerRadius(4.dp.toPx()), size = Size(size.width * frac, size.height))
        }
    }
}

/** Grouped vertical bars for two series. */
@Composable
private fun DualBarChart(
    labels: List<String>,
    a: List<Double>,
    b: List<Double>,
    colorA: Color,
    colorB: Color,
    modifier: Modifier = Modifier,
) {
    val maxV = max(a.maxOrNull() ?: 0.0, b.maxOrNull() ?: 0.0).coerceAtLeast(1.0)
    val n = max(labels.size, max(a.size, b.size)).coerceAtLeast(1)
    Column(modifier) {
        Canvas(Modifier.fillMaxWidth().weight(1f)) {
            val padL = 4.dp.toPx()
            val padT = 4.dp.toPx()
            val chartH = size.height - padT
            val slot = (size.width - padL) / n
            val barW = (slot * 0.32f).coerceAtLeast(2.dp.toPx())
            val gap = 2.dp.toPx()
            for (i in 0 until n) {
                val x0 = padL + i * slot + slot * 0.18f
                val ha = ((a.getOrElse(i) { 0.0 } / maxV) * chartH).toFloat()
                val hb = ((b.getOrElse(i) { 0.0 } / maxV) * chartH).toFloat()
                drawRoundRect(
                    colorA,
                    topLeft = Offset(x0, padT + chartH - ha),
                    size = Size(barW, ha.coerceAtLeast(1f)),
                    cornerRadius = CornerRadius(3.dp.toPx()),
                )
                drawRoundRect(
                    colorB,
                    topLeft = Offset(x0 + barW + gap, padT + chartH - hb),
                    size = Size(barW, hb.coerceAtLeast(1f)),
                    cornerRadius = CornerRadius(3.dp.toPx()),
                )
            }
            drawLine(
                Color(0xFFE2E8F0),
                Offset(padL, padT + chartH),
                Offset(size.width, padT + chartH),
                strokeWidth = 1.dp.toPx(),
                cap = StrokeCap.Round,
            )
        }
        Row(Modifier.fillMaxWidth()) {
            val step = if (labels.size > 8) (labels.size / 6).coerceAtLeast(1) else 1
            labels.forEachIndexed { i, lab ->
                if (i % step == 0 || i == labels.lastIndex) {
                    Text(
                        lab,
                        Modifier.weight(1f),
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                } else {
                    Spacer(Modifier.weight(1f))
                }
            }
        }
    }
}
