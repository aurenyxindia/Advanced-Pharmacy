package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import `in`.paperlesspharmacy.advanced.ui.util.formatInr
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private val OrderStatuses = listOf("placed", "confirmed", "packed", "out_for_delivery", "delivered", "cancelled")

private fun orderLabel(s: String): String = when (s) {
    "placed" -> "Placed"
    "confirmed" -> "Confirmed"
    "packed" -> "Packed"
    "out_for_delivery" -> "Out for delivery"
    "delivered" -> "Delivered"
    "cancelled" -> "Cancelled"
    else -> s
}

private fun orderColor(s: String): Color = when (s) {
    "placed" -> Color(0xFF475569)
    "confirmed" -> Color(0xFF0284C7)
    "packed" -> Color(0xFF2563EB)
    "out_for_delivery" -> Color(0xFFD97706)
    "delivered" -> Color(0xFF15803D)
    "cancelled" -> Color(0xFFDC2626)
    else -> PharmaMuted
}

private fun orderItems(o: JsonObject): List<JsonObject> =
    o.arr("items").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DeliveriesModuleScreen(vm: AppViewModel, onBack: () -> Unit) {
    val repo = remember { vm.care() }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val canEdit = vm.canEditModule("DELIVERIES")

    var allRows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var filter by remember { mutableStateOf("") }
    var search by remember { mutableStateOf("") }
    var openId by remember { mutableIntStateOf(0) }

    fun load() {
        scope.launch {
            loading = true
            error = null
            runCatching { withContext(Dispatchers.IO) { repo.orders(search.trim()).rows } }
                .onSuccess { allRows = it }
                .onFailure { error = careErr(it, "Could not load orders.") }
            loading = false
        }
    }

    LaunchedEffect(search) {
        if (search.isNotEmpty()) delay(400)
        load()
    }

    val rows = remember(allRows, filter) { if (filter.isBlank()) allRows else allRows.filter { it.str("status") == filter } }
    val active = allRows.count { it.str("status") !in setOf("delivered", "cancelled") }
    val outFor = allRows.count { it.str("status") == "out_for_delivery" }
    val estimate = allRows.sumOf { it.amount("total_estimate") }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PoHeader("Deliveries & Online Orders", "Manage ePharmacy orders and delivery status", onBack) {
            IconButton(onClick = { load() }) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
        }
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }

        CareKpiGrid(
            listOf(
                CareKpiItem("Orders", if (loading) "—" else allRows.size.toString(), PharmaTeal, Icons.Default.ShoppingBag),
                CareKpiItem("Active", if (loading) "—" else active.toString(), Color(0xFF2563EB), Icons.Default.HourglassTop),
                CareKpiItem("Out for Delivery", if (loading) "—" else outFor.toString(), Color(0xFFD97706), Icons.Default.LocalShipping, filter == "out_for_delivery") {
                    filter = if (filter == "out_for_delivery") "" else "out_for_delivery"
                },
                CareKpiItem("Est. Value", if (loading) "—" else formatInr(estimate), Color(0xFF475569), Icons.Default.Payments),
            ),
        )
        SalesDenseSearchField(
            value = search,
            onValueChange = { search = it },
            placeholder = "Search order no, customer or phone…",
            modifier = Modifier.fillMaxWidth(),
            leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
        )
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            item { PoChip("All ${allRows.size}", filter == "") { filter = "" } }
            items(OrderStatuses) { s ->
                val n = allRows.count { it.str("status") == s }
                PoChip("${orderLabel(s)} $n", filter == s) { filter = if (filter == s) "" else s }
            }
        }

        when {
            loading -> Box(Modifier.weight(1f)) { LoadingBlock("Loading orders…") }
            rows.isEmpty() -> CareEmpty(Icons.Default.LocalShipping, "No orders.", Modifier.weight(1f))
            else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(rows, key = { it.jInt("id") }) { r -> OrderCard(r) { openId = r.jInt("id") } }
            }
        }
    }

    if (openId > 0) {
        OrderDetailSheet(
            vm = vm,
            orderId = openId,
            canEdit = canEdit,
            onDismiss = { openId = 0 },
            onUpdated = { msg -> openId = 0; toast = msg; load() },
            onCall = { careDial(context, it) },
            onWhatsApp = { phone, text -> careWhatsApp(context, phone, text)?.let { error = it } },
        )
    }
}

@Composable
private fun OrderCard(r: JsonObject, onOpen: () -> Unit) {
    val st = r.str("status")
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color.White,
        border = BorderStroke(1.dp, PharmaBorder),
        modifier = Modifier.fillMaxWidth().clickable(onClick = onOpen),
    ) {
        Row(Modifier.height(IntrinsicSize.Min)) {
            Box(Modifier.width(4.dp).fillMaxHeight().background(orderColor(st)))
            Column(Modifier.padding(10.dp).weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(r.str("order_no"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate, modifier = Modifier.weight(1f))
                    CareBadge(orderLabel(st), orderColor(st))
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(r.str("customer_name"), style = MaterialTheme.typography.bodySmall, color = PharmaSlate, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(careMoney(r.amount("total_estimate")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                }
                Text(
                    listOf(r.str("phone"), careDateTime(r.str("created_at"))).filter { it.isNotBlank() && it != "—" }.joinToString(" · "),
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
                if (r.str("address").isNotBlank()) {
                    Text(r.str("address"), style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
                if (r.str("assigned_to").isNotBlank()) {
                    Text("Rider: ${r.str("assigned_to")}", style = MaterialTheme.typography.labelSmall, color = Color(0xFFD97706))
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OrderDetailSheet(
    vm: AppViewModel,
    orderId: Int,
    canEdit: Boolean,
    onDismiss: () -> Unit,
    onUpdated: (String) -> Unit,
    onCall: (String) -> Unit,
    onWhatsApp: (String, String) -> Unit,
) {
    val repo = remember { vm.care() }
    val scope = rememberCoroutineScope()
    var order by remember { mutableStateOf<JsonObject?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var newStatus by remember { mutableStateOf("") }
    var assignedTo by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var confirmCancel by remember { mutableStateOf(false) }

    LaunchedEffect(orderId) {
        runCatching { withContext(Dispatchers.IO) { repo.order(orderId) } }
            .onSuccess { o -> order = o; newStatus = o.str("status"); assignedTo = o.str("assigned_to"); note = "" }
            .onFailure { error = careErr(it, "Could not load order.") }
    }

    fun save(status: String, noteText: String) {
        val o = order ?: return
        saving = true
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.updateOrderStatus(o.jInt("id"), status, assignedTo.trim(), noteText.trim()) } }
                    .onSuccess { onUpdated(if (status == "cancelled") "Order cancelled · stock returned." else "Order updated.") }
                    .onFailure { error = careErr(it, "Could not update order.") }
            } finally {
                saving = false
            }
        }
    }

    fun trySave() {
        val o = order ?: return
        if (newStatus == "cancelled" && o.str("status") != "cancelled") confirmCancel = true else save(newStatus, note)
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Color.White) {
        val o = order
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(bottom = 24.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            error?.let { ErrorBanner(it) { error = null } }
            if (o == null) {
                if (error == null) Box(Modifier.fillMaxWidth().height(160.dp)) { LoadingBlock("Loading order…") }
                return@Column
            }
            val st = o.str("status")
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Order ${o.str("order_no")}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    Text("Placed ${careDateTime(o.str("created_at"))}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
                CareBadge(orderLabel(st), orderColor(st))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                CareInfo("Customer", o.str("customer_name"), Modifier.weight(1f))
                CareInfo("Phone", o.str("phone"), Modifier.weight(1f))
            }
            CareInfo("Address", listOf(o.str("address"), o.str("pincode")).filter { it.isNotBlank() }.joinToString(" "))
            if (o.str("notes").isNotBlank()) CareInfo("Customer note", o.str("notes"))
            if (o.str("phone").isNotBlank()) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    PharmaOutlinedButton("Call", Modifier.weight(1f)) { onCall(o.str("phone")) }
                    PharmaOutlinedButton("WhatsApp", Modifier.weight(1f)) {
                        onWhatsApp(o.str("phone"), "Hello ${o.str("customer_name")}, update on your order ${o.str("order_no")}: ${orderLabel(st)}.")
                    }
                }
            }

            val items = orderItems(o)
            Surface(shape = RoundedCornerShape(8.dp), border = BorderStroke(1.dp, PharmaBorder), color = Color.White) {
                Column(Modifier.fillMaxWidth()) {
                    Row(Modifier.fillMaxWidth().background(BillGridHeaderBg).padding(horizontal = 8.dp, vertical = 5.dp)) {
                        Text("Item", Modifier.weight(1f), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = PharmaMuted)
                        Text("Qty", Modifier.width(40.dp), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = PharmaMuted)
                        Text("Rate", Modifier.width(80.dp), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = PharmaMuted)
                    }
                    if (items.isEmpty()) Text("No items.", Modifier.padding(8.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                    items.forEach { it ->
                        Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 5.dp)) {
                            Text(it.str("product_name"), Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = PharmaSlate)
                            Text(it.str("quantity"), Modifier.width(40.dp), style = MaterialTheme.typography.bodySmall)
                            Text(careMoney(it.amount("rate")), Modifier.width(80.dp), style = MaterialTheme.typography.bodySmall)
                        }
                        HorizontalDivider(color = PharmaBorder)
                    }
                    Row(Modifier.fillMaxWidth().padding(8.dp)) {
                        Text("Estimated total", Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                        Text(careMoney(o.amount("total_estimate")), style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                    }
                }
            }

            if (canEdit) {
                POptionDropdown("Update Status", newStatus, OrderStatuses, { newStatus = it }, Modifier.fillMaxWidth(), display = { orderLabel(it) })
                SalesFormField("Assign To (rider)", assignedTo, { assignedTo = it.take(120) }, Modifier.fillMaxWidth())
                SalesFormField("Note", note, { note = it.take(255) }, Modifier.fillMaxWidth())
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (st != "cancelled" && st != "delivered") {
                        TextButton(onClick = { newStatus = "cancelled"; if (note.isBlank()) note = "Cancelled by pharmacy"; confirmCancel = true }) {
                            Text("Cancel & return stock", color = Color(0xFFB91C1C), style = MaterialTheme.typography.labelMedium)
                        }
                    }
                    Spacer(Modifier.weight(1f))
                    PharmaPrimaryButton(if (saving) "Saving…" else "Update Order", enabled = !saving) { trySave() }
                }
            } else {
                Text("You can view orders. Ask your admin for Deliveries edit access to update status.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
        }
    }

    if (confirmCancel) {
        CareConfirmDialog(
            title = "Cancel order?",
            text = "Cancel this order? Reserved stock will return to inventory.",
            confirmLabel = "Cancel order",
            danger = true,
            onConfirm = { confirmCancel = false; save("cancelled", note.ifBlank { "Cancelled by pharmacy" }) },
            onDismiss = { confirmCancel = false },
        )
    }
}
