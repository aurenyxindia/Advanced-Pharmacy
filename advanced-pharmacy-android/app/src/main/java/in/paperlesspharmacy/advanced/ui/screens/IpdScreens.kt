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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate
import kotlin.math.max
import kotlin.math.round

private val IpdWardTypes = listOf("General", "Ladies", "Private", "Semi-Private", "ICU", "Pediatric", "Maternity", "Deluxe")
private val IpdRelations = listOf(
    "Father", "Mother", "Son", "Daughter", "Brother", "Sister", "Uncle", "Aunt",
    "Nephew", "Niece", "Grandfather", "Grandmother", "Grandson", "Granddaughter",
    "Father-in-law", "Mother-in-law", "Son-in-law", "Daughter-in-law", "Cousin",
)
private val IpdPayModes = listOf("cash", "card", "upi", "bank")

private fun r2(v: Double) = round(v * 100) / 100

private fun JsonObject.has2(key: String) = has(key) && !get(key).isJsonNull

private data class IpdLine(
    val bindingId: Int,
    val productName: String,
    val batchId: Int?,
    val batchNo: String,
    val shelf: String,
    val category: String,
    val pack: String,
    val mrp: Double,
    val gstRate: Double,
    val qty: Double,
    val disc: Double,
    val orderDate: String,
    val cost: Double,
    val maxQty: Double,
    val uid: Long = System.nanoTime(),
) {
    val discAmt get() = mrp * qty * (disc / 100)
    val net get() = mrp * qty - discAmt
    val taxable get() = if (gstRate > 0) net / (1 + gstRate / 100) else net
    val gst get() = net - taxable
}

private class IpdBillState {
    var admissionId by mutableStateOf<Int?>(null)
    var admissionNo by mutableStateOf("")
    var priorBalance by mutableDoubleStateOf(0.0)
    var contact by mutableStateOf("")
    var patientName by mutableStateOf("")
    var email by mutableStateOf("")
    var address by mutableStateOf("")
    var hospital by mutableStateOf("")
    var doctor by mutableStateOf("")
    var wardType by mutableStateOf("")
    var wardNo by mutableStateOf("")
    var bed by mutableStateOf("")
    var relativePhone by mutableStateOf("")
    var relation by mutableStateOf("")
    var orderDate by mutableStateOf(LocalDate.now().toString())
    var paid by mutableStateOf("")
    val lines = mutableStateListOf<IpdLine>()

    fun reset() {
        admissionId = null; admissionNo = ""; priorBalance = 0.0
        contact = ""; patientName = ""; email = ""; address = ""; hospital = ""; doctor = ""
        wardType = ""; wardNo = ""; bed = ""; relativePhone = ""; relation = ""
        orderDate = LocalDate.now().toString(); paid = ""
        lines.clear()
    }

    fun applyAdmission(a: JsonObject) {
        admissionId = a.jInt("id")
        admissionNo = a.str("admission_no")
        priorBalance = if (a.has2("totals")) a.obj("totals").amount("balance") else a.amount("balance")
        contact = a.str("contact_no")
        patientName = a.str("patient_name")
        address = a.str("address")
        doctor = a.str("doctor_name")
        wardNo = a.str("ward")
        bed = a.str("bed_no")
    }

    val taxable get() = r2(lines.sumOf { it.taxable })
    val disc get() = r2(lines.sumOf { it.discAmt })
    val gst get() = r2(lines.sumOf { it.gst })
    val net get() = r2(lines.sumOf { it.net })
    val profit get() = r2(lines.sumOf { it.taxable - it.cost * it.qty })
    val balance get() = max(0.0, r2(priorBalance + net - (paid.toDoubleOrNull() ?: 0.0)))
}

private fun admStatusColor(s: String) = if (s == "admitted") Color(0xFF15803D) else Color(0xFF64748B)

@Composable
fun IpdModuleScreen(vm: AppViewModel, onBack: () -> Unit) {
    val repo = remember { vm.care() }
    val scope = rememberCoroutineScope()
    val canEdit = vm.canEditModule("IPD")

    var tab by rememberSaveableInt(0)
    var allRows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var filter by remember { mutableStateOf("admitted") }
    var search by remember { mutableStateOf("") }
    var detailId by remember { mutableIntStateOf(0) }
    var admitOpen by remember { mutableStateOf(false) }
    val bill = remember { IpdBillState() }

    fun load() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.admissions() } }
                .onSuccess { allRows = it }
                .onFailure { error = careErr(it, "Could not load admissions.") }
            loading = false
        }
    }
    LaunchedEffect(Unit) { load() }

    val admittedCount = allRows.count { it.str("status") == "admitted" }
    val billed = r2(allRows.sumOf { it.amount("billed") })
    val paid = r2(allRows.sumOf { it.amount("paid") })
    val outstanding = r2(allRows.filter { it.str("status") == "admitted" }.sumOf { it.amount("balance") })

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PoHeader("In-Patient (IPD) Admissions", "Bills accrue to an admission until discharge; partial payments are tracked.", onBack) {
            IconButton(onClick = { load() }) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
            if (canEdit) PharmaPrimaryButton("Admit", Modifier.heightIn(max = 34.dp)) { admitOpen = true }
        }
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }

        CareKpiGrid(
            listOf(
                CareKpiItem("Admitted", if (loading) "—" else admittedCount.toString(), PharmaTeal, Icons.Default.LocalHospital),
                CareKpiItem("Total Billed", if (loading) "—" else rptMoney(billed), Color(0xFF475569), Icons.Default.Receipt),
                CareKpiItem("Total Paid", if (loading) "—" else rptMoney(paid), Color(0xFF15803D), Icons.Default.Payments),
                CareKpiItem("Outstanding", if (loading) "—" else rptMoney(outstanding), if (outstanding > 0) Color(0xFFDC2626) else Color(0xFF475569), Icons.Default.AccountBalanceWallet),
            ),
        )
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            PoChip("Admissions", tab == 0) { tab = 0 }
            if (canEdit) PoChip("IPD Billing", tab == 1) { tab = 1 }
        }

        if (tab == 0) {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                item { PoChip("Admitted $admittedCount", filter == "admitted") { filter = "admitted" } }
                item { PoChip("Discharged ${allRows.size - admittedCount}", filter == "discharged") { filter = "discharged" } }
                item { PoChip("All ${allRows.size}", filter == "") { filter = "" } }
            }
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it },
                placeholder = "Search patient, admission no, ward, doctor…",
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
            val rows = remember(allRows, filter, search) {
                val q = search.trim().lowercase()
                allRows.filter { r ->
                    (filter.isBlank() || r.str("status") == filter) &&
                        (q.isEmpty() || listOf("patient_name", "admission_no", "ward", "bed_no", "doctor_name", "contact_no").any { r.str(it).lowercase().contains(q) })
                }
            }
            when {
                loading -> Box(Modifier.weight(1f)) { LoadingBlock("Loading admissions…") }
                rows.isEmpty() -> CareEmpty(Icons.Default.LocalHospital, "No admissions.", Modifier.weight(1f))
                else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(rows, key = { it.jInt("id") }) { r -> AdmissionCard(r) { detailId = r.jInt("id") } }
                }
            }
        } else {
            IpdBillingTab(
                vm = vm,
                bill = bill,
                admitted = allRows.filter { it.str("status") == "admitted" },
                modifier = Modifier.weight(1f),
                onCancel = { bill.reset(); tab = 0 },
                onSaved = { admissionId ->
                    toast = "IPD bill saved."
                    bill.reset()
                    load()
                    tab = 0
                    detailId = admissionId
                },
            )
        }
    }

    if (detailId > 0) {
        AdmissionDetailSheet(
            vm = vm,
            id = detailId,
            canEdit = canEdit,
            onDismiss = { detailId = 0 },
            onChanged = { msg -> toast = msg; load() },
            onAddBill = { adm ->
                detailId = 0
                bill.reset()
                bill.applyAdmission(adm)
                tab = 1
            },
        )
    }

    if (admitOpen) {
        AdmitDialog(
            onDismiss = { admitOpen = false },
            onSave = { body, done ->
                scope.launch {
                    runCatching { withContext(Dispatchers.IO) { repo.admit(body) } }
                        .onSuccess { admitOpen = false; toast = "Patient admitted."; load() }
                        .onFailure { done(careErr(it, "Could not admit patient.")) }
                }
            },
        )
    }
}

@Composable
private fun rememberSaveableInt(initial: Int): MutableIntState =
    androidx.compose.runtime.saveable.rememberSaveable { mutableIntStateOf(initial) }

@Composable
private fun AdmissionCard(r: JsonObject, onOpen: () -> Unit) {
    val st = r.str("status")
    val balance = r.amount("balance")
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color.White,
        border = BorderStroke(1.dp, PharmaBorder),
        modifier = Modifier.fillMaxWidth().clickable(onClick = onOpen),
    ) {
        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(r.str("patient_name"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                CareBadge(st, admStatusColor(st))
            }
            Text(
                "${r.str("admission_no")} · Ward ${r.str("ward").ifBlank { "-" }} / Bed ${r.str("bed_no").ifBlank { "-" }}" +
                    (r.str("doctor_name").takeIf { it.isNotBlank() }?.let { " · Dr. $it" } ?: ""),
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text("Admitted ${careDateTime(r.str("admitted_at"))}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            Row(Modifier.fillMaxWidth().padding(top = 2.dp)) {
                IpdMini("Billed", rptMoney(r.amount("billed")), PharmaSlate, Modifier.weight(1f))
                IpdMini("Paid", rptMoney(r.amount("paid")), Color(0xFF15803D), Modifier.weight(1f))
                IpdMini("Balance", rptMoney(balance), if (balance > 0) Color(0xFFDC2626) else PharmaSlate, Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun IpdMini(label: String, value: String, color: Color, modifier: Modifier = Modifier) {
    Column(modifier) {
        Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        Text(value, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold, color = color)
    }
}

@Composable
private fun IpdTotBox(label: String, value: Double, color: Color, modifier: Modifier) {
    Surface(shape = RoundedCornerShape(8.dp), color = color.copy(alpha = 0.07f), border = BorderStroke(1.dp, color.copy(alpha = 0.25f)), modifier = modifier) {
        Column(Modifier.padding(8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            Text(rptMoney(value), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = color)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AdmissionDetailSheet(
    vm: AppViewModel,
    id: Int,
    canEdit: Boolean,
    onDismiss: () -> Unit,
    onChanged: (String) -> Unit,
    onAddBill: (JsonObject) -> Unit,
) {
    val repo = remember { vm.care() }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var adm by remember { mutableStateOf<JsonObject?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var payOpen by remember { mutableStateOf(false) }
    var confirmDischarge by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }

    fun reload() {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.admission(id) } }
                .onSuccess { adm = it }
                .onFailure { error = careErr(it, "Could not load admission.") }
        }
    }
    LaunchedEffect(id) { reload() }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Color.White) {
        val a = adm
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(bottom = 24.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            error?.let { ErrorBanner(it) { error = null } }
            if (a == null) {
                if (error == null) Box(Modifier.fillMaxWidth().height(160.dp)) { LoadingBlock("Loading admission…") }
                return@Column
            }
            val st = a.str("status")
            val totals = a.obj("totals")
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(a.str("patient_name"), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    Text(
                        "${a.str("admission_no")} · ${a.str("ward").ifBlank { "-" }}/${a.str("bed_no").ifBlank { "-" }} · Dr. ${a.str("doctor_name").ifBlank { "-" }}",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                    )
                }
                CareBadge(st, admStatusColor(st))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                CareInfo("Contact", a.str("contact_no"), Modifier.weight(1f))
                CareInfo("Admitted", careDateTime(a.str("admitted_at")), Modifier.weight(1f))
            }
            if (a.str("address").isNotBlank() || a.str("discharged_at").isNotBlank()) {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    CareInfo("Address", a.str("address"), Modifier.weight(1f))
                    if (a.str("discharged_at").isNotBlank()) CareInfo("Discharged", careDateTime(a.str("discharged_at")), Modifier.weight(1f))
                }
            }
            if (a.str("contact_no").isNotBlank()) {
                TextButton(onClick = { careDial(context, a.str("contact_no")) }, contentPadding = PaddingValues(0.dp)) {
                    Icon(Icons.Default.Call, null, Modifier.size(16.dp)); Spacer(Modifier.width(4.dp)); Text("Call patient", style = MaterialTheme.typography.labelMedium)
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                IpdTotBox("Billed", totals.amount("billed"), PharmaSlate, Modifier.weight(1f))
                IpdTotBox("Paid", totals.amount("paid"), Color(0xFF15803D), Modifier.weight(1f))
                IpdTotBox("Balance", totals.amount("balance"), if (totals.amount("balance") > 0) Color(0xFFDC2626) else PharmaSlate, Modifier.weight(1f))
            }
            if (st == "admitted" && canEdit) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    PharmaPrimaryButton("Add Bill", Modifier.weight(1f)) { onAddBill(a) }
                    PharmaOutlinedButton("Payment", Modifier.weight(1f)) { payOpen = true }
                    OutlinedButton(
                        onClick = { confirmDischarge = true },
                        enabled = !busy,
                        modifier = Modifier.weight(1f).height(CompactButtonHeight),
                        shape = RoundedCornerShape(8.dp),
                        border = BorderStroke(1.dp, Color(0xFFDC2626)),
                        contentPadding = PaddingValues(horizontal = 6.dp),
                    ) { Text("Discharge", color = Color(0xFFDC2626), style = MaterialTheme.typography.labelLarge) }
                }
            }

            val bills = a.arr("bills").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
            Text("BILLS (${bills.size})", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaMuted)
            if (bills.isEmpty()) Text("No bills yet.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
            bills.forEach { b ->
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(6.dp)).clickable {
                        scope.launch {
                            runCatching { withContext(Dispatchers.IO) { vm.sales().invoicePdf(b.jInt("id")) } }
                                .onSuccess { bytes -> careOpenPdf(context, bytes, "IPD_${b.str("invoice_no").ifBlank { b.jInt("id").toString() }}.pdf")?.let { error = it } }
                                .onFailure { error = careErr(it, "Could not open bill PDF.") }
                        }
                    }.background(Color(0xFFF8FAFC)).padding(horizontal = 8.dp, vertical = 7.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Default.PictureAsPdf, null, Modifier.size(16.dp), tint = Color(0xFFB91C1C))
                    Spacer(Modifier.width(6.dp))
                    Text(b.str("invoice_no"), Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    Text(careDate(b.str("invoice_date")), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    Spacer(Modifier.width(10.dp))
                    Text(rptMoney(b.amount("net_amount")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
                }
            }

            val pays = a.arr("payments").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
            Text("PAYMENTS (${pays.size})", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaMuted)
            if (pays.isEmpty()) Text("No payments yet.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
            pays.forEach { p ->
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(6.dp)).background(Color(0xFFF0FDF4)).padding(horizontal = 8.dp, vertical = 7.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    CareBadge(p.str("mode").uppercase(), PharmaSlate, Color.White)
                    Spacer(Modifier.width(6.dp))
                    Column(Modifier.weight(1f)) {
                        Text(careDate(p.str("paid_at")), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        if (p.str("note").isNotBlank()) Text(p.str("note"), style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    Text(rptMoney(p.amount("amount")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = Color(0xFF15803D))
                }
            }
        }
    }

    if (payOpen) {
        val a = adm
        IpdPaymentDialog(
            balance = a?.obj("totals")?.amount("balance") ?: 0.0,
            onDismiss = { payOpen = false },
            onSave = { amount, mode, note, done ->
                scope.launch {
                    runCatching { withContext(Dispatchers.IO) { repo.ipdPayment(id, amount, mode, note) } }
                        .onSuccess { payOpen = false; onChanged("Payment recorded."); reload() }
                        .onFailure { done(careErr(it, "Could not record payment.")) }
                }
            },
        )
    }

    if (confirmDischarge) {
        CareConfirmDialog(
            title = "Discharge patient?",
            text = "Discharge ${adm?.str("patient_name")}? Final settlement will be locked.",
            confirmLabel = "Discharge",
            danger = true,
            onConfirm = {
                confirmDischarge = false
                busy = true
                scope.launch {
                    try {
                        runCatching { withContext(Dispatchers.IO) { repo.discharge(id) } }
                            .onSuccess { res -> onChanged("Discharged. Balance due: ₹${rptMoney(res.amount("balance"))}"); reload() }
                            .onFailure { error = careErr(it, "Could not discharge.") }
                    } finally {
                        busy = false
                    }
                }
            },
            onDismiss = { confirmDischarge = false },
        )
    }
}

@Composable
private fun IpdPaymentDialog(balance: Double, onDismiss: () -> Unit, onSave: (Double, String, String, (String) -> Unit) -> Unit) {
    var amount by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("cash") }
    var note by remember { mutableStateOf("") }
    var err by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    Dialog(onDismissRequest = onDismiss) {
        Surface(shape = RoundedCornerShape(12.dp), color = Color.White) {
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Record Payment", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                Text("Balance due: ₹${rptMoney(balance)}", style = MaterialTheme.typography.labelMedium, color = if (balance > 0) Color(0xFFDC2626) else PharmaMuted)
                err?.let { ErrorBanner(it) { err = null } }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Bottom) {
                    SalesFormField("Amount *", amount, { amount = it.filter { c -> c.isDigit() || c == '.' } }, Modifier.weight(1f), keyboardType = KeyboardType.Decimal)
                    if (balance > 0) PharmaOutlinedButton("Full") { amount = r2(balance).toString() }
                }
                POptionDropdown("Mode", mode, IpdPayModes, { mode = it }, Modifier.fillMaxWidth(), display = { it.replaceFirstChar { c -> c.uppercase() }.let { s -> if (s == "Upi") "UPI" else s } })
                SalesFormField("Note", note, { note = it.take(255) }, Modifier.fillMaxWidth())
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End)) {
                    PharmaOutlinedButton("Cancel", onClick = onDismiss)
                    PharmaPrimaryButton(if (saving) "Saving…" else "Save Payment", enabled = !saving) {
                        val amt = amount.toDoubleOrNull() ?: 0.0
                        if (amt < 1) { err = "Enter an amount of at least ₹1."; return@PharmaPrimaryButton }
                        saving = true
                        onSave(amt, mode, note.trim()) { e -> saving = false; err = e }
                    }
                }
            }
        }
    }
}

@Composable
private fun AdmitDialog(onDismiss: () -> Unit, onSave: (JsonObject, (String) -> Unit) -> Unit) {
    var name by remember { mutableStateOf("") }
    var contact by remember { mutableStateOf("") }
    var doctor by remember { mutableStateOf("") }
    var ward by remember { mutableStateOf("") }
    var bed by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var err by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    Dialog(onDismissRequest = onDismiss) {
        Surface(shape = RoundedCornerShape(12.dp), color = Color.White, modifier = Modifier.heightIn(max = 620.dp)) {
            Column(Modifier.padding(14.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Admit Patient", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                err?.let { ErrorBanner(it) { err = null } }
                SalesFormField("Patient Name *", name, { name = it.take(150) }, Modifier.fillMaxWidth(), isError = err != null && name.isBlank())
                SalesFormField("Contact", contact, { contact = it.filter { c -> c.isDigit() || c == '+' }.take(20) }, Modifier.fillMaxWidth(), keyboardType = KeyboardType.Phone)
                SalesFormField("Doctor", doctor, { doctor = it.take(150) }, Modifier.fillMaxWidth())
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SalesFormField("Ward", ward, { ward = it.take(60) }, Modifier.weight(1f))
                    SalesFormField("Bed No", bed, { bed = it.take(30) }, Modifier.weight(1f))
                }
                SalesFormField("Address", address, { address = it.take(255) }, Modifier.fillMaxWidth())
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End)) {
                    PharmaOutlinedButton("Cancel", onClick = onDismiss)
                    PharmaPrimaryButton(if (saving) "Saving…" else "Admit", enabled = !saving) {
                        if (name.isBlank()) { err = "Patient name is required."; return@PharmaPrimaryButton }
                        saving = true
                        val body = JsonObject().apply {
                            addProperty("patient_name", name.trim())
                            if (contact.isNotBlank()) addProperty("contact_no", contact.trim())
                            if (doctor.isNotBlank()) addProperty("doctor_name", doctor.trim())
                            if (ward.isNotBlank()) addProperty("ward", ward.trim())
                            if (bed.isNotBlank()) addProperty("bed_no", bed.trim())
                            if (address.isNotBlank()) addProperty("address", address.trim())
                        }
                        onSave(body) { e -> saving = false; err = e }
                    }
                }
            }
        }
    }
}

// ───────────────────────── IPD Billing ─────────────────────────

private fun batchMrp(b: JsonObject?, p: JsonObject?): Double = when {
    b != null && b.has2("unit_mrp") -> b.amount("unit_mrp")
    b != null && b.has2("mrp") -> b.amount("mrp")
    else -> p?.amount("mrp") ?: 0.0
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun IpdBillingTab(
    vm: AppViewModel,
    bill: IpdBillState,
    admitted: List<JsonObject>,
    modifier: Modifier,
    onCancel: () -> Unit,
    onSaved: (Int) -> Unit,
) {
    val repo = remember { vm.care() }
    val sales = remember { vm.sales() }
    val scope = rememberCoroutineScope()
    val wide = LocalConfiguration.current.screenWidthDp >= 600

    var doctors by remember { mutableStateOf<List<String>>(emptyList()) }
    var generics by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var genericId by remember { mutableIntStateOf(0) }
    var genericPicker by remember { mutableStateOf(false) }
    var products by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var productsLoading by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var showDrop by remember { mutableStateOf(false) }
    var draftProduct by remember { mutableStateOf<JsonObject?>(null) }
    var draftBatches by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var draftBatch by remember { mutableStateOf<JsonObject?>(null) }
    var draftQty by remember { mutableStateOf("1") }
    var draftDisc by remember { mutableStateOf("0") }
    var err by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }

    fun loadProducts() {
        productsLoading = true
        scope.launch {
            products = runCatching { withContext(Dispatchers.IO) { sales.searchProducts("", genericId) } }.getOrElse { emptyList() }
            productsLoading = false
        }
    }
    LaunchedEffect(Unit) {
        doctors = runCatching { withContext(Dispatchers.IO) { repo.doctors().map { it.str("name") }.filter { it.isNotBlank() } } }.getOrElse { emptyList() }
        generics = runCatching { withContext(Dispatchers.IO) { repo.generics() } }.getOrElse { emptyList() }
    }
    LaunchedEffect(genericId) { loadProducts() }

    fun onContact(v: String) {
        bill.contact = v.filter { it.isDigit() }.take(12)
        val digits = bill.contact
        if (digits.length < 10) return
        scope.launch {
            val c = runCatching { withContext(Dispatchers.IO) { sales.lookupCustomers(digits).firstOrNull() } }.getOrNull() ?: return@launch
            if (c.str("name").isNotBlank() && bill.admissionId == null) bill.patientName = c.str("name")
            if (c.str("email").isNotBlank()) bill.email = c.str("email")
            if (c.str("address").isNotBlank()) bill.address = c.str("address")
        }
    }

    fun onPatient(idStr: String) {
        val id = idStr.toIntOrNull() ?: 0
        if (id == 0) {
            bill.admissionId = null; bill.admissionNo = ""; bill.priorBalance = 0.0; bill.patientName = ""
            return
        }
        admitted.firstOrNull { it.jInt("id") == id }?.let { bill.applyAdmission(it) }
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.admission(id) } }.onSuccess { bill.applyAdmission(it) }
        }
    }

    fun selectProduct(p: JsonObject) {
        showDrop = false
        query = p.str("product_name")
        draftProduct = p
        draftBatches = emptyList()
        draftBatch = null
        draftQty = "1"
        draftDisc = "0"
        scope.launch {
            val list = runCatching { withContext(Dispatchers.IO) { sales.batches(p.jInt("binding_id")) } }.getOrElse { emptyList() }
            draftBatches = list
            draftBatch = list.firstOrNull { it.amount("quantity") > 0 } ?: list.firstOrNull()
        }
    }

    fun addLine() {
        val p = draftProduct ?: run { err = "Select a product."; return }
        val b = draftBatch ?: run { err = "Select a batch."; return }
        val qty = draftQty.toDoubleOrNull() ?: 0.0
        if (qty <= 0) { err = "Enter quantity."; return }
        val avail = b.amount("quantity")
        if (qty > avail) { err = "Only ${ipdQ(avail)} units available in batch."; return }
        bill.lines.add(
            IpdLine(
                bindingId = p.jInt("binding_id"),
                productName = p.str("product_name"),
                batchId = b.jInt("batch_id").takeIf { it > 0 },
                batchNo = b.str("batch_no"),
                shelf = b.str("shelf_location").ifBlank { p.str("shelf_location") },
                category = p.str("schedule_category"),
                pack = p.str("pack_size"),
                mrp = batchMrp(b, p),
                gstRate = p.amount("gst_rate"),
                qty = qty,
                disc = draftDisc.toDoubleOrNull() ?: 0.0,
                orderDate = bill.orderDate,
                cost = b.amount("purchase_rate"),
                maxQty = avail,
            ),
        )
        err = null
        query = ""
        draftProduct = null
        draftBatches = emptyList()
        draftBatch = null
        draftQty = "1"
        draftDisc = "0"
    }

    fun save() {
        if (bill.patientName.isBlank()) { err = "Patient name is required."; return }
        if (bill.relativePhone.isBlank()) { err = "Relative phone is required."; return }
        if (bill.lines.isEmpty()) { err = "Add at least one product."; return }
        saving = true
        err = null
        scope.launch {
            val result = runCatching {
                withContext(Dispatchers.IO) {
                    val admissionId = bill.admissionId ?: run {
                        val ward = listOf(bill.wardType, bill.wardNo).filter { it.isNotBlank() }.joinToString(" / ")
                        repo.admit(JsonObject().apply {
                            addProperty("patient_name", bill.patientName.trim())
                            if (bill.contact.isNotBlank()) addProperty("contact_no", bill.contact)
                            if (bill.address.isNotBlank()) addProperty("address", bill.address)
                            if (bill.doctor.isNotBlank()) addProperty("doctor_name", bill.doctor)
                            if (ward.isNotBlank()) addProperty("ward", ward)
                            if (bill.bed.isNotBlank()) addProperty("bed_no", bill.bed)
                        }).also { bill.admissionId = it }
                    }
                    val narration = listOf(
                        bill.hospital.takeIf { it.isNotBlank() }?.let { "Hospital: $it" },
                        bill.wardType.takeIf { it.isNotBlank() }?.let { "Ward Type: $it" },
                        bill.wardNo.takeIf { it.isNotBlank() }?.let { "Ward No: $it" },
                        bill.bed.takeIf { it.isNotBlank() }?.let { "Bed: $it" },
                        bill.relation.takeIf { it.isNotBlank() }?.let { "Relation: $it" },
                        bill.relativePhone.takeIf { it.isNotBlank() }?.let { "Relative Ph: $it" },
                    ).filterNotNull().joinToString(" | ")
                    val body = JsonObject().apply {
                        addProperty("sale_type", "IPD")
                        addProperty("bill_mode", "retail")
                        addProperty("customer_name", bill.patientName.trim())
                        addProperty("patient_name", bill.patientName.trim())
                        if (bill.contact.isNotBlank()) addProperty("contact_no", bill.contact)
                        if (bill.email.isNotBlank()) addProperty("email", bill.email)
                        if (bill.address.isNotBlank()) addProperty("address", bill.address)
                        if (bill.doctor.isNotBlank()) addProperty("doctor_name", bill.doctor)
                        if (narration.isNotBlank()) addProperty("narration", narration)
                        addProperty("payment_mode", "credit")
                        addProperty("gst_inclusive", 1)
                        addProperty("ipd_admission_id", admissionId)
                        addProperty("invoice_date", bill.orderDate)
                        add("items", JsonArray().apply {
                            bill.lines.forEach { l ->
                                add(JsonObject().apply {
                                    addProperty("product_binding_id", l.bindingId)
                                    addProperty("schedule_category", l.category)
                                    addProperty("quantity", round(l.qty).toInt())
                                    addProperty("rate", r2(l.mrp))
                                    addProperty("discount_pct", l.disc)
                                    l.batchId?.let { addProperty("batch_id", it) }
                                    addProperty("unit_type", "unit")
                                    if (l.pack.isNotBlank()) addProperty("pack", l.pack)
                                    if (l.shelf.isNotBlank()) addProperty("shelf", l.shelf)
                                })
                            }
                        })
                    }
                    sales.create(body)
                    val paid = bill.paid.toDoubleOrNull() ?: 0.0
                    if (paid > 0) runCatching { repo.ipdPayment(admissionId, paid, "cash", "IPD bill payment") }
                    admissionId
                }
            }
            saving = false
            result.onSuccess { onSaved(it) }.onFailure { err = careErr(it, "Could not save IPD bill.") }
        }
    }

    val filtered = remember(products, query) {
        val q = query.trim().lowercase()
        (if (q.isEmpty()) products else products.filter {
            it.str("product_name").lowercase().contains(q) || it.str("manufacturer_short").lowercase().contains(q) || it.str("generic_name").lowercase().contains(q)
        }).take(40)
    }
    val fieldW = if (wide) 200.dp else 156.dp
    val genericName = generics.firstOrNull { it.jInt("id") == genericId }?.str("name").orEmpty()

    Column(modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        err?.let { ErrorBanner(it) { err = null } }

        IpdSection("Customer Details") {
            if (bill.admissionId != null) {
                CareBadge("Linked admission ${bill.admissionNo.ifBlank { "#${bill.admissionId}" }} · prior balance ₹${rptMoney(bill.priorBalance)}", PharmaTealDark)
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                SalesFormField("Contact No.", bill.contact, { onContact(it) }, Modifier.width(fieldW), placeholder = "Contact No.", keyboardType = KeyboardType.Phone)
                POptionDropdown(
                    "Patient Name *",
                    bill.admissionId?.toString() ?: "",
                    listOf("") + admitted.map { it.jInt("id").toString() },
                    { onPatient(it) },
                    Modifier.width(fieldW),
                    display = { v ->
                        if (v.isBlank()) "— New / select IPD patient —"
                        else admitted.firstOrNull { it.jInt("id").toString() == v }?.let { "${it.str("patient_name")} · ${it.str("admission_no")}" } ?: bill.patientName
                    },
                )
                if (bill.admissionId == null) {
                    SalesFormField("New patient name *", bill.patientName, { bill.patientName = it.take(150) }, Modifier.width(fieldW), placeholder = "Creates admission on save")
                }
                SalesFormField("Email", bill.email, { bill.email = it.trim() }, Modifier.width(fieldW), keyboardType = KeyboardType.Email)
                SalesFormField("Address", bill.address, { bill.address = it.take(255) }, Modifier.width(fieldW))
                SalesFormField("Hospital Name", bill.hospital, { bill.hospital = it }, Modifier.width(fieldW))
                POptionDropdown("Doctor Name", bill.doctor, listOf("") + doctors.let { d -> if (bill.doctor.isNotBlank() && bill.doctor !in d) d + bill.doctor else d }, { bill.doctor = it }, Modifier.width(fieldW), display = { it.ifBlank { "— Doctor —" } })
                POptionDropdown("Ward Type", bill.wardType, listOf("") + IpdWardTypes, { bill.wardType = it }, Modifier.width(fieldW), display = { it.ifBlank { "— Ward Type —" } })
                SalesFormField("Ward No.", bill.wardNo, { bill.wardNo = it.take(60) }, Modifier.width(fieldW))
                SalesFormField("Bed No.", bill.bed, { bill.bed = it.take(30) }, Modifier.width(fieldW))
                SalesFormField("Relative Ph. No. *", bill.relativePhone, { bill.relativePhone = it.filter { c -> c.isDigit() || c == '+' }.take(15) }, Modifier.width(fieldW), keyboardType = KeyboardType.Phone, isError = err != null && bill.relativePhone.isBlank())
                POptionDropdown("Relation With Patient", bill.relation, listOf("") + IpdRelations, { bill.relation = it }, Modifier.width(fieldW), display = { it.ifBlank { "— Relation —" } })
                PDateField("Bill Date", bill.orderDate, { bill.orderDate = minOf(it, LocalDate.now().toString()) }, Modifier.width(fieldW))
            }
        }

        IpdSection("Billing") {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    border = BorderStroke(1.dp, PharmaBorder),
                    color = Color.White,
                    modifier = Modifier.weight(1f).height(SalesDenseFieldHeight).clip(RoundedCornerShape(6.dp)).clickable { genericPicker = true },
                ) {
                    Row(Modifier.padding(horizontal = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.FilterAlt, null, Modifier.size(14.dp), tint = PharmaMuted)
                        Spacer(Modifier.width(6.dp))
                        Text(genericName.ifBlank { "Search by generic name — all generics" }, style = MaterialTheme.typography.labelMedium, color = if (genericName.isBlank()) PharmaMuted else PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
                if (genericId > 0) TextButton(onClick = { genericId = 0 }) { Text("Clear Filter", color = Color(0xFFB91C1C), style = MaterialTheme.typography.labelMedium) }
            }
            SalesDenseSearchField(
                value = query,
                onValueChange = { query = it; showDrop = true; if (draftProduct != null && it != draftProduct?.str("product_name")) draftProduct = null },
                placeholder = if (productsLoading) "Loading products…" else "Search product…",
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
                trailingIcon = if (query.isNotEmpty()) ({ Icon(Icons.Default.Close, "Clear", Modifier.size(16.dp).clickable { query = ""; draftProduct = null; showDrop = false }, tint = PharmaMuted) }) else null,
                onFocusChanged = { if (it.isFocused) showDrop = true },
            )
            if (showDrop && draftProduct == null) {
                Surface(shape = RoundedCornerShape(8.dp), border = BorderStroke(1.dp, PharmaBorder), color = Color.White) {
                    Column(Modifier.fillMaxWidth().heightIn(max = 300.dp).verticalScroll(rememberScrollState())) {
                        when {
                            productsLoading -> Text("Loading…", Modifier.padding(8.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                            filtered.isEmpty() -> Text("No products.", Modifier.padding(8.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                            else -> filtered.forEach { p ->
                                Column(Modifier.fillMaxWidth().clickable { selectProduct(p) }.padding(horizontal = 10.dp, vertical = 6.dp)) {
                                    Text(p.str("product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                    Text("${p.str("manufacturer_short").ifBlank { "-" }} · Qty ${ipdQ(p.amount("available_qty"))}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                }
                                HorizontalDivider(color = PharmaBorder)
                            }
                        }
                    }
                }
            }

            draftProduct?.let { p ->
                val b = draftBatch
                val mrp = batchMrp(b, p)
                val gstRate = p.amount("gst_rate")
                val q = draftQty.toDoubleOrNull() ?: 0.0
                val d = draftDisc.toDoubleOrNull() ?: 0.0
                val gross = mrp * q
                val discAmt = gross * d / 100
                val net = gross - discAmt
                val taxable = if (gstRate > 0) net / (1 + gstRate / 100) else net
                Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF0FDFA), border = BorderStroke(1.dp, PharmaTeal.copy(alpha = 0.3f))) {
                    Column(Modifier.fillMaxWidth().padding(8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(p.str("product_name"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            if (draftBatches.isNotEmpty()) {
                                POptionDropdown(
                                    "Batch",
                                    b?.jInt("batch_id")?.toString() ?: "",
                                    draftBatches.map { it.jInt("batch_id").toString() },
                                    { v -> draftBatch = draftBatches.firstOrNull { it.jInt("batch_id").toString() == v } },
                                    Modifier.width(fieldW),
                                    display = { v ->
                                        draftBatches.firstOrNull { it.jInt("batch_id").toString() == v }?.let {
                                            "${it.str("batch_no")} (${ipdQ(it.amount("quantity"))})" +
                                                (it.str("expiry_date").takeIf { e -> e.length >= 7 }?.let { e -> " ${e.substring(5, 7)}/${e.substring(2, 4)}" } ?: "")
                                        } ?: "— Batch —"
                                    },
                                )
                            } else {
                                PReadField("Batch", "No stock batches", Modifier.width(fieldW))
                            }
                            SalesFormField("Qty", draftQty, { draftQty = it.filter { c -> c.isDigit() } }, Modifier.width(80.dp), keyboardType = KeyboardType.Number)
                            SalesFormField("Disc%", draftDisc, { draftDisc = it.filter { c -> c.isDigit() || c == '.' } }, Modifier.width(80.dp), keyboardType = KeyboardType.Decimal)
                        }
                        Text(
                            "Shelf ${b?.str("shelf_location")?.ifBlank { null } ?: p.str("shelf_location").ifBlank { "-" }} · ${p.str("schedule_category").ifBlank { "-" }} · Pack ${p.str("pack_size").ifBlank { "-" }} · MRP ${rptMoney(mrp)} · GST ${ipdQ(gstRate)}%",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                        )
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                "Taxable ${rptMoney(taxable)} · Disc ${rptMoney(discAmt)} · GST ${rptMoney(net - taxable)}",
                                style = MaterialTheme.typography.labelSmall,
                                color = PharmaSlate,
                                modifier = Modifier.weight(1f),
                            )
                            Text("Net ₹${rptMoney(net)}", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                        }
                        PharmaPrimaryButton("ADD", Modifier.fillMaxWidth()) { addLine() }
                    }
                }
            }

            if (bill.lines.isEmpty()) {
                Text("Note: Add products then Save bill.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
            bill.lines.toList().forEach { l ->
                key(l.uid) {
                    IpdLineCard(
                        l = l,
                        onQty = { v -> bill.lines.indexOfFirst { it.uid == l.uid }.takeIf { it >= 0 }?.let { bill.lines[it] = bill.lines[it].copy(qty = v) } },
                        onDisc = { v -> bill.lines.indexOfFirst { it.uid == l.uid }.takeIf { it >= 0 }?.let { bill.lines[it] = bill.lines[it].copy(disc = v) } },
                        onRemove = { bill.lines.removeAll { it.uid == l.uid } },
                    )
                }
            }
        }

        IpdSection("Totals") {
            IpdTotRow("Taxable", bill.taxable)
            IpdTotRow("Discount", bill.disc)
            IpdTotRow("GST", bill.gst)
            IpdTotRow("Net (this bill)", bill.net, bold = true)
            if (bill.priorBalance != 0.0) IpdTotRow("Prior admission balance", bill.priorBalance)
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                SalesFormField("Paid Amt.", bill.paid, { bill.paid = it.filter { c -> c.isDigit() || c == '.' } }, Modifier.weight(1f), placeholder = "0", keyboardType = KeyboardType.Decimal)
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.End) {
                    Text("Balance", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    Text("₹${rptMoney(bill.balance)}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = if (bill.balance > 0) Color(0xFFDC2626) else PharmaSlate)
                }
            }
            Text("Bill is saved on credit to the admission; Paid Amt. is recorded as a cash payment.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        }

        Row(Modifier.fillMaxWidth().padding(bottom = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End)) {
            PharmaOutlinedButton("Cancel", onClick = onCancel)
            PharmaPrimaryButton(if (saving) "Saving…" else "Save", enabled = !saving) { save() }
        }
    }

    if (genericPicker) {
        IpdGenericPicker(
            generics = generics,
            selected = genericId,
            onPick = { genericId = it; genericPicker = false },
            onDismiss = { genericPicker = false },
        )
    }
}

private fun ipdQ(v: Double): String =
    if (v == kotlin.math.floor(v)) v.toLong().toString() else java.math.BigDecimal.valueOf(v).stripTrailingZeros().toPlainString()

@Composable
private fun IpdSection(title: String, content: @Composable ColumnScope.() -> Unit) {
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
        Column(Modifier.fillMaxWidth().padding(10.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(title, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            content()
        }
    }
}

@Composable
private fun IpdTotRow(label: String, value: Double, bold: Boolean = false) {
    Row(Modifier.fillMaxWidth()) {
        Text(label, Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
        Text("₹${rptMoney(value)}", style = MaterialTheme.typography.labelLarge, fontWeight = if (bold) FontWeight.Bold else FontWeight.SemiBold, color = if (bold) PharmaTealDark else PharmaSlate)
    }
}

@Composable
private fun IpdLineCard(l: IpdLine, onQty: (Double) -> Unit, onDisc: (Double) -> Unit, onRemove: () -> Unit) {
    var qtyText by remember { mutableStateOf(pNumText(l.qty, true)) }
    var discText by remember { mutableStateOf(pNumText(l.disc, false)) }
    Surface(shape = RoundedCornerShape(8.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
        Column(Modifier.fillMaxWidth().padding(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(l.productName, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    Text(
                        "${careDate(l.orderDate)} · B.No ${l.batchNo} · Shelf ${l.shelf.ifBlank { "-" }} · ${l.category.ifBlank { "-" }} · Pack ${l.pack.ifBlank { "-" }} · MRP ${rptMoney(l.mrp)}",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                    )
                }
                IconButton(onClick = onRemove, Modifier.size(32.dp)) { Icon(Icons.Default.Delete, "Remove", Modifier.size(18.dp), tint = Color(0xFFDC2626)) }
            }
            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                SalesFormField("Qty", qtyText, { t ->
                    qtyText = t.filter { it.isDigit() }
                    onQty(qtyText.toDoubleOrNull() ?: 0.0)
                }, Modifier.width(70.dp), keyboardType = KeyboardType.Number, isError = l.qty <= 0 || l.qty > l.maxQty)
                SalesFormField("Disc%", discText, { t ->
                    discText = t.filter { it.isDigit() || it == '.' }
                    onDisc(discText.toDoubleOrNull() ?: 0.0)
                }, Modifier.width(70.dp), keyboardType = KeyboardType.Decimal)
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.End) {
                    Text("Tax ${rptMoney(l.taxable)} · Disc ${rptMoney(l.discAmt)} · GST ${rptMoney(l.gst)}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted, textAlign = TextAlign.End)
                    Text("₹${rptMoney(l.net)}", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold, color = PharmaSlate)
                }
            }
            if (l.qty > l.maxQty) Text("Only ${ipdQ(l.maxQty)} units in this batch.", style = MaterialTheme.typography.labelSmall, color = Color(0xFFDC2626))
        }
    }
}

@Composable
private fun IpdGenericPicker(generics: List<JsonObject>, selected: Int, onPick: (Int) -> Unit, onDismiss: () -> Unit) {
    var q by remember { mutableStateOf("") }
    val list = remember(q, generics) {
        val t = q.trim().lowercase()
        (if (t.isEmpty()) generics else generics.filter { it.str("name").lowercase().contains(t) }).take(300)
    }
    Dialog(onDismissRequest = onDismiss) {
        Surface(shape = RoundedCornerShape(12.dp), color = Color.White, modifier = Modifier.fillMaxWidth().heightIn(max = 560.dp)) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Search By Generic Name", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                SalesDenseSearchField(
                    value = q,
                    onValueChange = { q = it },
                    placeholder = "Type generic…",
                    modifier = Modifier.fillMaxWidth(),
                    leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
                )
                LazyColumn(Modifier.weight(1f, fill = false)) {
                    if (q.isBlank()) item {
                        Text("— All generics —", Modifier.fillMaxWidth().clickable { onPick(0) }.padding(8.dp), style = MaterialTheme.typography.bodySmall, color = if (selected == 0) PharmaTeal else PharmaSlate)
                    }
                    items(list, key = { it.jInt("id") }) { g ->
                        Text(
                            g.str("name"),
                            Modifier.fillMaxWidth().clickable { onPick(g.jInt("id")) }.padding(8.dp),
                            style = MaterialTheme.typography.bodySmall,
                            color = if (g.jInt("id") == selected) PharmaTeal else PharmaSlate,
                            fontWeight = if (g.jInt("id") == selected) FontWeight.SemiBold else FontWeight.Normal,
                        )
                    }
                    if (list.isEmpty()) item { Text("No generics found.", Modifier.padding(8.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted) }
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { TextButton(onClick = onDismiss) { Text("Close") } }
            }
        }
    }
}
