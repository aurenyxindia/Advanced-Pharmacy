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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
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
import java.time.LocalDate

private val RefillStatuses = listOf("" to "All", "pending" to "Pending", "notified" to "Notified", "completed" to "Completed", "cancelled" to "Cancelled")

private fun refillStatusColor(s: String): Color = when (s) {
    "pending" -> Color(0xFF475569)
    "notified" -> Color(0xFF0284C7)
    "completed" -> Color(0xFF15803D)
    else -> Color(0xFF94A3B8)
}

private fun refillOverdue(r: JsonObject): Boolean {
    val d = r.str("refill_date").take(10)
    val st = r.str("status")
    return d.isNotBlank() && d < LocalDate.now().toString() && (st == "pending" || st == "notified")
}

@Composable
fun RefillModuleScreen(vm: AppViewModel, onBack: () -> Unit) {
    val repo = remember { vm.care() }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val canEdit = vm.canEditModule("REFILLS")

    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var summary by remember { mutableStateOf(JsonObject()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var due by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var search by remember { mutableStateOf("") }
    var newOpen by remember { mutableStateOf(false) }
    var pharmacyName by remember { mutableStateOf("") }
    var busyId by remember { mutableIntStateOf(0) }

    fun load() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                withContext(Dispatchers.IO) { repo.refills(due, status) to repo.refillSummary() }
            }.onSuccess { (r, s) -> rows = r; summary = s }
                .onFailure { error = careErr(it, "Could not load reminders.") }
            loading = false
        }
    }

    fun mark(r: JsonObject, newStatus: String, channel: String? = null, okMsg: String = "Reminder updated.") {
        busyId = r.jInt("id")
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.updateRefill(r.jInt("id"), newStatus, channel) } }
                .onSuccess { toast = okMsg; load() }
                .onFailure { error = careErr(it, "Could not update reminder.") }
            busyId = 0
        }
    }

    LaunchedEffect(due, status) { load() }
    LaunchedEffect(Unit) {
        pharmacyName = runCatching { withContext(Dispatchers.IO) { vm.api.get("profile").obj("data").str("business_name") } }.getOrDefault("")
    }

    val shown = remember(rows, search) {
        val q = search.trim().lowercase()
        if (q.isEmpty()) rows else rows.filter {
            it.str("customer_name").lowercase().contains(q) || it.str("phone").contains(q) || it.str("product_name").lowercase().contains(q)
        }
    }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PoHeader("Refill Reminders", "Follow up with patients for prescription refills", onBack) {
            IconButton(onClick = { load() }) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
            if (canEdit) PharmaPrimaryButton("+ New", Modifier.heightIn(max = 34.dp)) { newOpen = true }
        }
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }

        CareKpiGrid(
            listOf(
                CareKpiItem("Overdue", summary.jInt("overdue").toString(), Color(0xFFDC2626), Icons.Default.Error, due == "overdue") { due = if (due == "overdue") "" else "overdue" },
                CareKpiItem("Due Today", summary.jInt("today").toString(), Color(0xFFD97706), Icons.Default.NotificationsActive, due == "today") { due = if (due == "today") "" else "today" },
                CareKpiItem("Next 7 days", summary.jInt("upcoming").toString(), Color(0xFF0284C7), Icons.Default.DateRange, due == "upcoming") { due = if (due == "upcoming") "" else "upcoming" },
            ),
        )
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            items(RefillStatuses) { (k, label) -> PoChip(label, status == k) { status = k } }
        }
        SalesDenseSearchField(
            value = search,
            onValueChange = { search = it },
            placeholder = "Search customer, phone or product…",
            modifier = Modifier.fillMaxWidth(),
            leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
        )
        if (due.isNotBlank()) {
            Text(
                "Showing ${when (due) { "overdue" -> "overdue"; "today" -> "due today"; else -> "next 7 days" }} · tap the card again to clear",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
        }

        when {
            loading -> Box(Modifier.weight(1f)) { LoadingBlock("Loading reminders…") }
            shown.isEmpty() -> CareEmpty(Icons.Default.NotificationsOff, "No reminders.", Modifier.weight(1f))
            else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(shown, key = { it.jInt("id") }) { r ->
                    RefillCard(
                        r = r,
                        canEdit = canEdit,
                        busy = busyId == r.jInt("id"),
                        onCall = { careDial(context, r.str("phone")) },
                        onWhatsApp = {
                            val msg = buildString {
                                append("Hello ${r.str("customer_name")},\n")
                                append("This is a refill reminder")
                                if (pharmacyName.isNotBlank()) append(" from $pharmacyName")
                                append(".")
                                if (r.str("product_name").isNotBlank()) append(" Your medicine ${r.str("product_name")}")
                                else append(" Your medicines")
                                append(" will be due on ${careDate(r.str("refill_date"))}.")
                                append(" Reply to this message to order your refill.")
                            }
                            val err = careWhatsApp(context, r.str("phone"), msg)
                            if (err != null) error = err
                            else if (canEdit && r.str("status") == "pending") mark(r, "notified", "whatsapp", "Marked as notified via WhatsApp.")
                        },
                        onMark = { s -> mark(r, s) },
                    )
                }
            }
        }
    }

    if (newOpen) {
        RefillNewDialog(
            onDismiss = { newOpen = false },
            onSave = { body, done ->
                scope.launch {
                    runCatching { withContext(Dispatchers.IO) { repo.createRefill(body) } }
                        .onSuccess { res ->
                            newOpen = false
                            toast = "Reminder scheduled for ${careDate(res.str("refill_date"))}."
                            load()
                        }
                        .onFailure { done(careErr(it, "Could not schedule reminder.")) }
                }
            },
        )
    }
}

@Composable
private fun RefillCard(
    r: JsonObject,
    canEdit: Boolean,
    busy: Boolean,
    onCall: () -> Unit,
    onWhatsApp: () -> Unit,
    onMark: (String) -> Unit,
) {
    val st = r.str("status")
    val open = st == "pending" || st == "notified"
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
        Column(Modifier.fillMaxWidth().padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(r.str("customer_name"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                CareBadge(st.replaceFirstChar { it.uppercase() }, refillStatusColor(st))
            }
            if (r.str("product_name").isNotBlank()) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.Medication, null, Modifier.size(14.dp), tint = PharmaMuted)
                    Spacer(Modifier.width(4.dp))
                    Text(r.str("product_name"), style = MaterialTheme.typography.bodySmall, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(Icons.Default.Event, null, Modifier.size(14.dp), tint = PharmaMuted)
                Text("Refill ${careDate(r.str("refill_date"))}", style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                if (refillOverdue(r)) CareBadge("overdue", Color(0xFFDC2626))
                Spacer(Modifier.weight(1f))
                Text(r.str("phone").ifBlank { "No phone" }, style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
            }
            if (r.str("channel").isNotBlank() || r.str("notes").isNotBlank()) {
                Text(
                    listOf(r.str("channel").takeIf { it.isNotBlank() }?.let { "Via ${it}" }, r.str("notes").takeIf { it.isNotBlank() }).filterNotNull().joinToString(" · "),
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            Row(horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.CenterVertically) {
                if (r.str("phone").isNotBlank()) {
                    IconButton(onClick = onCall, Modifier.size(34.dp)) { Icon(Icons.Default.Call, "Call", Modifier.size(18.dp), tint = PharmaTeal) }
                    IconButton(onClick = onWhatsApp, Modifier.size(34.dp)) { Icon(Icons.Default.Chat, "WhatsApp", Modifier.size(18.dp), tint = Color(0xFF15803D)) }
                }
                Spacer(Modifier.weight(1f))
                if (busy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                else if (open && canEdit) {
                    if (st == "pending") TextButton(onClick = { onMark("notified") }) { Text("Notified", style = MaterialTheme.typography.labelMedium, color = Color(0xFF0284C7)) }
                    TextButton(onClick = { onMark("completed") }) { Text("Completed", style = MaterialTheme.typography.labelMedium, color = Color(0xFF15803D)) }
                    TextButton(onClick = { onMark("cancelled") }) { Text("Cancel", style = MaterialTheme.typography.labelMedium, color = PharmaMuted) }
                }
            }
        }
    }
}

@Composable
private fun RefillNewDialog(onDismiss: () -> Unit, onSave: (JsonObject, (String) -> Unit) -> Unit) {
    var name by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var product by remember { mutableStateOf("") }
    var days by remember { mutableStateOf("30") }
    var date by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var err by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }

    val daysInt = days.toIntOrNull()
    val preview = when {
        date.isNotBlank() -> careDate(date)
        daysInt != null && daysInt in 1..365 -> careDate(LocalDate.now().plusDays(daysInt.toLong()).toString())
        else -> ""
    }

    Dialog(onDismissRequest = onDismiss) {
        Surface(shape = RoundedCornerShape(12.dp), color = Color.White, modifier = Modifier.fillMaxWidth().heightIn(max = 640.dp)) {
            Column(Modifier.padding(14.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("New Refill Reminder", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                err?.let { ErrorBanner(it) { err = null } }
                SalesFormField("Customer Name *", name, { name = it.take(150) }, Modifier.fillMaxWidth(), isError = err != null && name.isBlank())
                SalesFormField("Phone", phone, { phone = it.filter { c -> c.isDigit() || c == '+' }.take(20) }, Modifier.fillMaxWidth(), keyboardType = KeyboardType.Phone)
                SalesFormField("Product / Medication", product, { product = it.take(200) }, Modifier.fillMaxWidth())
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SalesFormField("Days Supply", days, { days = it.filter { c -> c.isDigit() }.take(3) }, Modifier.weight(1f), keyboardType = KeyboardType.Number, isError = date.isBlank() && (daysInt == null || daysInt !in 1..365))
                    PDateField("Refill date (optional)", date, { date = it }, Modifier.weight(1f))
                }
                if (date.isNotBlank()) {
                    Text("Clear date to use days supply", style = MaterialTheme.typography.labelSmall, color = PharmaTeal, modifier = Modifier.clickable { date = "" })
                }
                if (preview.isNotBlank()) Text("Reminder on $preview", style = MaterialTheme.typography.labelMedium, color = PharmaTealDark, fontWeight = FontWeight.SemiBold)
                SalesFormField("Notes", notes, { notes = it.take(255) }, Modifier.fillMaxWidth())
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End)) {
                    PharmaOutlinedButton("Cancel", onClick = onDismiss)
                    PharmaPrimaryButton(if (saving) "Saving…" else "Schedule", enabled = !saving) {
                        when {
                            name.isBlank() -> err = "Customer name is required."
                            date.isBlank() && (daysInt == null || daysInt !in 1..365) -> err = "Days supply must be between 1 and 365, or pick a refill date."
                            else -> {
                                saving = true
                                val body = JsonObject().apply {
                                    addProperty("customer_name", name.trim())
                                    if (phone.isNotBlank()) addProperty("phone", phone.trim())
                                    if (product.isNotBlank()) addProperty("product_name", product.trim())
                                    if (date.isNotBlank()) addProperty("refill_date", date) else addProperty("days_supply", daysInt)
                                    if (notes.isNotBlank()) addProperty("notes", notes.trim())
                                }
                                onSave(body) { e -> saving = false; err = e }
                            }
                        }
                    }
                }
            }
        }
    }
}
