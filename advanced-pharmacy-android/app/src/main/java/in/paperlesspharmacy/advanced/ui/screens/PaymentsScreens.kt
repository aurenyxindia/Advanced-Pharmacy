package `in`.paperlesspharmacy.advanced.ui.screens

import android.content.Context
import android.content.Intent
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.transaction.PaymentsRepository
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.time.LocalDate

private fun payR2(v: Double): Double = Math.round(v * 100) / 100.0

private fun payOpenHtml(context: Context, bytes: ByteArray, fileName: String): String? =
    runCatching {
        val file = File(context.cacheDir, fileName).apply { writeBytes(bytes) }
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
        context.startActivity(
            Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, "text/html")
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
            },
        )
    }.exceptionOrNull()?.let { "No browser found to open the receipt." }

private fun payPrintFromSettle(context: Context, repo: PaymentsRepository, res: JsonObject): String? {
    val ids = mutableListOf<Int>()
    val alloc = res.get("allocation_ids")
    if (alloc != null && alloc.isJsonArray) {
        alloc.asJsonArray.forEach { e -> if (e.isJsonPrimitive) ids += e.asInt }
    } else {
        val applied = res.get("applied")
        if (applied != null && applied.isJsonArray) {
            applied.asJsonArray.forEach { e ->
                if (e.isJsonObject) {
                    val id = e.asJsonObject.jInt("allocation_id")
                    if (id > 0) ids += id
                }
            }
        }
    }
    var err: String? = null
    for (id in ids.filter { it > 0 }) {
        runCatching { repo.printAllocationHtml(id) }
            .onSuccess { bytes -> payOpenHtml(context, bytes, "payment_receipt_$id.html")?.let { err = it } }
            .onFailure { err = careErr(it, "Could not open receipt.") }
    }
    return err
}

private enum class PayTab(val label: String) {
    Distributor("Distributor"),
    Retail("Retail Customer"),
    Wholesale("Wholesale Customer"),
    Vouchers("Vouchers"),
}

private val ModesDist = listOf("Cash", "UPI", "Cheque", "Bank / Card")
private val ModesCust = listOf("Cash", "Cheque", "Bank / Card")

@Composable
fun PaymentsModuleScreen(vm: AppViewModel, onBack: () -> Unit, showHeader: Boolean = true) {
    val biz by vm.businessType.collectAsState()
    val showRetail = biz == "retail" || biz == "both" || biz.isBlank()
    val showWholesale = biz == "wholesale" || biz == "both"
    var tab by rememberSaveable { mutableStateOf(PayTab.Distributor.name) }
    val tabs = remember(showRetail, showWholesale) {
        buildList {
            add(PayTab.Distributor)
            if (showRetail) add(PayTab.Retail)
            if (showWholesale) add(PayTab.Wholesale)
            add(PayTab.Vouchers)
        }
    }
    val current = tabs.firstOrNull { it.name == tab } ?: PayTab.Distributor
    LaunchedEffect(tabs) {
        if (tabs.none { it.name == tab }) tab = tabs.first().name
    }

    Column(
        Modifier
            .fillMaxSize()
            .then(if (showHeader) Modifier.padding(CompactScreenPadding) else Modifier),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        if (showHeader) {
            PoHeader("Payments", "Settle dues against bills · vouchers for cashbook", onBack)
        }
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            items(tabs) { t -> PoChip(t.label, current == t) { tab = t.name } }
        }
        Box(Modifier.weight(1f)) {
            when (current) {
                PayTab.Distributor -> DistPayTab(vm)
                PayTab.Retail -> CustPayTab(vm, wholesale = false)
                PayTab.Wholesale -> CustPayTab(vm, wholesale = true)
                PayTab.Vouchers -> VouchersTab(vm)
            }
        }
    }
}

/** Accounts hub: Chart of Accounts (ledgers) + Payments. */
@Composable
fun AccountsHubScreen(vm: AppViewModel, onBack: () -> Unit, startOnPayments: Boolean = false) {
    var tab by rememberSaveable { mutableStateOf(if (startOnPayments) "payments" else "ledgers") }
    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PoHeader(
            if (tab == "ledgers") "Accounts" else "Payments",
            if (tab == "ledgers") "Chart of accounts / ledgers" else "Settle dues against bills · vouchers for cashbook",
            onBack,
        )
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            item { PoChip("Chart of Accounts", tab == "ledgers") { tab = "ledgers" } }
            item { PoChip("Payments", tab == "payments") { tab = "payments" } }
        }
        Box(Modifier.weight(1f)) {
            when (tab) {
                "ledgers" -> MasterCrudScreen(vm, "accounts", showBack = false, onBack = onBack)
                else -> PaymentsModuleScreen(vm, onBack = onBack, showHeader = false)
            }
        }
    }
}

// ───────────────────────── Distributor ─────────────────────────

@Composable
private fun DistPayTab(vm: AppViewModel) {
    val repo = remember { vm.payments() }
    val scope = rememberCoroutineScope()
    val canEdit = vm.canEditModule("PAYMENTS")
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }
    var detail by remember { mutableStateOf<JsonObject?>(null) }
    var history by remember { mutableStateOf(false) }
    var histRows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }

    fun load() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.distributorDues() } }
                .onSuccess { rows = it }
                .onFailure { error = careErr(it, "Failed to load distributor dues.") }
            loading = false
        }
    }
    LaunchedEffect(Unit) { load() }

    val shown = remember(rows, search) {
        val q = search.trim().lowercase()
        if (q.isEmpty()) rows else rows.filter { it.str("distributor_name").lowercase().contains(q) }
    }

    when {
        detail != null -> DistSettleForm(
            party = detail!!,
            canEdit = canEdit,
            repo = repo,
            onClose = { detail = null },
            onSaved = { detail = null; toast = "Distributor payment saved."; load() },
            onError = { error = it },
        )
        history -> Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            error?.let { ErrorBanner(it) { error = null } }
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconButton(onClick = { history = false }, Modifier.size(32.dp)) { Icon(Icons.Default.ArrowBack, "Back", tint = PharmaSlate) }
                Text("Distributor Payment History", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = PharmaSlate, modifier = Modifier.weight(1f))
            }
            when {
                loading -> Box(Modifier.weight(1f)) { LoadingBlock() }
                histRows.isEmpty() -> CareEmpty(Icons.Default.History, "No history.", Modifier.weight(1f))
                else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(histRows, key = { it.jInt("id") }) { h -> HistoryCard(h, showDistributor = true) }
                }
            }
        }
        else -> Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            error?.let { ErrorBanner(it) { error = null } }
            toast?.let { PoToast(it) { toast = null } }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                SalesDenseSearchField(
                    value = search,
                    onValueChange = { search = it },
                    placeholder = "Search distributor…",
                    modifier = Modifier.weight(1f),
                    leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
                )
                IconButton(onClick = { load() }, Modifier.size(34.dp)) { Icon(Icons.Default.Refresh, null, tint = PharmaTeal) }
                PharmaOutlinedButton("History", Modifier.heightIn(max = 34.dp)) {
                    history = true
                    loading = true
                    scope.launch {
                        runCatching { withContext(Dispatchers.IO) { repo.history("distributor") } }
                            .onSuccess { histRows = it }
                            .onFailure { histRows = emptyList() }
                        loading = false
                    }
                }
            }
            if (rows.isNotEmpty()) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SalesLogKpiChip("Distributors", rows.size.toString(), Modifier.weight(1f))
                    SalesLogKpiChip("Total due", careMoney(rows.sumOf { it.amount("balance") }), Modifier.weight(1.4f))
                }
            }
            when {
                loading -> Box(Modifier.weight(1f)) { LoadingBlock("Loading dues…") }
                shown.isEmpty() -> CareEmpty(Icons.Default.LocalShipping, "No pending distributor dues.", Modifier.weight(1f))
                else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(shown, key = { it.str("distributor_name") }) { p ->
                        DuePartyCard(
                            name = p.str("distributor_name"),
                            contact = "",
                            net = p.amount("net"),
                            bills = p.jInt("total_dues"),
                            from = p.str("from_date"),
                            balance = p.amount("balance"),
                            onOpen = { detail = p.deepCopy() },
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun DistSettleForm(
    party: JsonObject,
    canEdit: Boolean,
    repo: PaymentsRepository,
    onClose: () -> Unit,
    onSaved: () -> Unit,
    onError: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val details = remember { party.arr("details").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject?.deepCopy() }.toMutableStateList() }
    var amountText by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("Cash") }
    var txn by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf<String?>(null) }

    val max = payR2(details.sumOf { it.amount("balance") })

    fun applyFifo() {
        var left = amountText.toDoubleOrNull() ?: 0.0
        if (left > max) {
            left = max
            amountText = if (max % 1.0 == 0.0) max.toLong().toString() else max.toString()
        }
        for (d in details) {
            val bal = d.amount("balance")
            val pay = if (left > 0) minOf(left, bal) else 0.0
            d.addProperty("payment_amount", payR2(pay))
            d.addProperty("balance_amount", payR2(bal - pay))
            d.addProperty("payment_mode", mode)
            d.addProperty("transaction_no", txn)
            left = payR2(left - pay)
        }
        val snap = details.toList()
        details.clear()
        details.addAll(snap)
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onClose, Modifier.size(32.dp)) { Icon(Icons.Default.ArrowBack, null, tint = PharmaSlate) }
            Text(party.str("distributor_name"), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = PharmaSlate, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        err?.let { ErrorBanner(it) { err = null } }
        Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
            Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row {
                    CareInfo("Balance", careMoney(party.amount("balance")), Modifier.weight(1f))
                    CareInfo("Due bills", party.jInt("total_dues").toString(), Modifier.weight(1f))
                    CareInfo("Due from", careDate(party.str("from_date")), Modifier.weight(1f))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SalesFormField(
                        "Amount (optional FIFO)",
                        amountText,
                        { v -> if (v.isEmpty() || Regex("""^\d{0,9}(\.\d{0,2})?$""").matches(v)) { amountText = v; applyFifo() } },
                        Modifier.weight(1f),
                        keyboardType = KeyboardType.Decimal,
                    )
                    POptionDropdown("Mode", mode, ModesDist, { mode = it; applyFifo() }, Modifier.weight(1f))
                }
                SalesFormField("Transaction / Cheque No", txn, { txn = it; applyFifo() }, Modifier.fillMaxWidth())
                Text("Max: ${careMoney(max)}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
        }
        LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            items(details.size) { i ->
                val d = details[i]
                Surface(shape = RoundedCornerShape(8.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                    Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(d.str("invoice_no"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                        Row {
                            CareInfo("Date", careDate(d.str("invoice_date")), Modifier.weight(1f))
                            CareInfo("Amount", careMoney(d.amount("net_total")), Modifier.weight(1f))
                            CareInfo("Paid", careMoney(d.amount("paid")), Modifier.weight(1f))
                            CareInfo("Remaining", careMoney(d.amount("balance")), Modifier.weight(1f))
                        }
                        if (canEdit) {
                            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                SalesFormField(
                                    "Pay now",
                                    d.amount("payment_amount").let { if (it == 0.0) "" else if (it % 1.0 == 0.0) it.toLong().toString() else it.toString() },
                                    { v ->
                                        if (v.isEmpty() || Regex("""^\d{0,9}(\.\d{0,2})?$""").matches(v)) {
                                            var pay = v.toDoubleOrNull() ?: 0.0
                                            val bal = d.amount("balance")
                                            if (pay < 0) pay = 0.0
                                            if (pay > bal) pay = bal
                                            d.addProperty("payment_amount", payR2(pay))
                                            d.addProperty("balance_amount", payR2(bal - pay))
                                            d.addProperty("payment_mode", mode)
                                            d.addProperty("transaction_no", txn)
                                            details[i] = d
                                        }
                                    },
                                    Modifier.weight(1f),
                                    keyboardType = KeyboardType.Decimal,
                                )
                                CareInfo("Balance", careMoney(d.amount("balance_amount")), Modifier.weight(1f))
                            }
                        }
                    }
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PharmaOutlinedButton("Close", Modifier.weight(1f), onClick = onClose)
            if (canEdit) {
                PharmaPrimaryButton(if (saving) "Saving…" else "Save", Modifier.weight(1.3f), enabled = !saving) {
                    saving = true
                    err = null
                    for (d in details) {
                        if (d.amount("payment_amount") > 0) {
                            d.addProperty("payment_mode", mode)
                            d.addProperty("transaction_no", txn)
                        }
                    }
                    val body = JsonObject().apply {
                        addProperty("distributor_name", party.str("distributor_name"))
                        addProperty("mode", mode)
                        addProperty("transaction_no", txn)
                        add("details", JsonArray().apply { details.forEach { add(it) } })
                    }
                    scope.launch {
                        try {
                            runCatching { withContext(Dispatchers.IO) { repo.settleDistributor(body) } }
                                .onSuccess { onSaved() }
                                .onFailure { e -> err = careErr(e, "Save failed."); onError(err!!) }
                        } finally {
                            saving = false
                        }
                    }
                }
            }
        }
    }
}

// ───────────────────────── Retail / Wholesale customer ─────────────────────────

@Composable
private fun CustPayTab(vm: AppViewModel, wholesale: Boolean) {
    val repo = remember { vm.payments() }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val canEdit = vm.canEditModule("PAYMENTS")
    var sub by rememberSaveable { mutableStateOf("payment") }
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var cleared by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }
    var detail by remember { mutableStateOf<JsonObject?>(null) }
    var partyHist by remember { mutableStateOf<List<JsonObject>>(emptyList()) }

    fun loadDues() {
        scope.launch {
            loading = true
            runCatching {
                withContext(Dispatchers.IO) {
                    if (wholesale) repo.wholesaleCustomerDues() else repo.customerDues()
                }
            }.onSuccess { rows = it }
                .onFailure { error = careErr(it, "Failed to load customer dues.") }
            loading = false
        }
    }

    fun loadCleared() {
        scope.launch {
            loading = true
            runCatching {
                withContext(Dispatchers.IO) { repo.history(if (wholesale) "wcustomer" else "customer") }
            }.onSuccess { cleared = it }
                .onFailure { cleared = emptyList() }
            loading = false
        }
    }

    LaunchedEffect(sub) {
        if (sub == "payment") loadDues() else loadCleared()
    }

    val shownCleared = remember(cleared, search) {
        val q = search.trim().lowercase()
        if (q.isEmpty()) cleared else cleared.filter {
            it.str("patient_name").lowercase().contains(q) ||
                it.str("party_name").lowercase().contains(q) ||
                it.str("bill_no").lowercase().contains(q) ||
                it.str("contact_no").contains(q)
        }
    }

    if (detail != null) {
        CustSettleForm(
            party = detail!!,
            wholesale = wholesale,
            canEdit = canEdit,
            partyHistory = partyHist,
            repo = repo,
            onClose = { detail = null },
            onSaved = { res ->
                detail = null
                toast = if (wholesale) "Wholesale payment saved." else "Customer payment saved."
                loadDues()
                scope.launch {
                    withContext(Dispatchers.IO) { payPrintFromSettle(context, repo, res) }?.let { error = it }
                }
            },
            onError = { error = it },
        )
        return
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            PoChip(if (wholesale) "Wholesale Payment" else "Retail Payment", sub == "payment") { sub = "payment" }
            PoChip("Payment History", sub == "history") { sub = "history" }
            Spacer(Modifier.weight(1f))
            IconButton(
                onClick = { if (sub == "payment") loadDues() else loadCleared() },
                Modifier.size(34.dp),
            ) { Icon(Icons.Default.Refresh, null, tint = PharmaTeal) }
        }
        if (sub == "history") {
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it },
                placeholder = "Search customer / bill…",
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
        }
        when {
            loading -> Box(Modifier.weight(1f)) { LoadingBlock() }
            sub == "payment" && rows.isEmpty() -> CareEmpty(
                Icons.Default.Person,
                if (wholesale) "No pending wholesale dues." else "No pending retail dues.",
                Modifier.weight(1f),
            )
            sub == "history" && shownCleared.isEmpty() -> CareEmpty(Icons.Default.History, "No cleared payments found.", Modifier.weight(1f))
            sub == "payment" -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(rows, key = { it.str("patient_name") + "|" + it.str("contact_no") }) { p ->
                    DuePartyCard(
                        name = p.str("patient_name"),
                        contact = p.str("contact_no"),
                        net = p.amount("net"),
                        bills = p.jInt("total_dues"),
                        from = p.str("from_date"),
                        balance = p.amount("balance"),
                        onOpen = {
                            detail = p.deepCopy()
                            partyHist = emptyList()
                            scope.launch {
                                runCatching {
                                    withContext(Dispatchers.IO) {
                                        repo.history(if (wholesale) "wcustomer" else "customer", p.str("patient_name"), p.str("contact_no"))
                                    }
                                }.onSuccess { partyHist = it }
                            }
                        },
                    )
                }
            }
            else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(shownCleared, key = { it.jInt("id") }) { h ->
                    HistoryCard(
                        h,
                        showDistributor = false,
                        onPrint = {
                            scope.launch {
                                runCatching { withContext(Dispatchers.IO) { repo.printAllocationHtml(h.jInt("id")) } }
                                    .onSuccess { bytes -> payOpenHtml(context, bytes, "payment_receipt_${h.jInt("id")}.html")?.let { error = it } }
                                    .onFailure { error = careErr(it, "Failed to open print PDF.") }
                            }
                        },
                    )
                }
            }
        }
    }
}

@Composable
private fun CustSettleForm(
    party: JsonObject,
    wholesale: Boolean,
    canEdit: Boolean,
    partyHistory: List<JsonObject>,
    repo: PaymentsRepository,
    onClose: () -> Unit,
    onSaved: (JsonObject) -> Unit,
    onError: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val details = remember { party.arr("details").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject?.deepCopy() }.toMutableStateList() }
    var amountText by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("Cash") }
    var txn by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf<String?>(null) }
    val max = payR2(details.sumOf { it.amount("balance") })

    fun applyFifo() {
        if (!wholesale) return
        var left = amountText.toDoubleOrNull() ?: 0.0
        if (left > max) {
            left = max
            amountText = if (max % 1.0 == 0.0) max.toLong().toString() else max.toString()
        }
        for (d in details) {
            val bal = d.amount("balance")
            val pay = if (left > 0) minOf(left, bal) else 0.0
            d.addProperty("payment_amount", payR2(pay))
            d.addProperty("balance_amount", payR2(bal - pay))
            d.addProperty("payment_mode", mode)
            d.addProperty("transaction_no", txn)
            left = payR2(left - pay)
        }
        val snap = details.toList()
        details.clear()
        details.addAll(snap)
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onClose, Modifier.size(32.dp)) { Icon(Icons.Default.ArrowBack, null, tint = PharmaSlate) }
            Column(Modifier.weight(1f)) {
                Text(party.str("patient_name"), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (party.str("contact_no").isNotBlank()) Text(party.str("contact_no"), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
        }
        err?.let { ErrorBanner(it) { err = null } }
        Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
            Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SalesFormField(
                        if (wholesale) "Amount (FIFO)" else "Amount to Pay *",
                        amountText,
                        { v ->
                            if (v.isEmpty() || Regex("""^\d{0,9}(\.\d{0,2})?$""").matches(v)) {
                                amountText = v
                                applyFifo()
                            }
                        },
                        Modifier.weight(1f),
                        keyboardType = KeyboardType.Decimal,
                    )
                    POptionDropdown("Mode", mode, ModesCust, { mode = it; applyFifo() }, Modifier.weight(1f))
                }
                SalesFormField("Transaction No", txn, { txn = it; applyFifo() }, Modifier.fillMaxWidth())
                Text("Max: ${careMoney(max)}${if (!wholesale) " (FIFO oldest bills)" else ""}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
        }
        LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            items(details.size) { i ->
                val d = details[i]
                Surface(shape = RoundedCornerShape(8.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                    Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Text(d.str("invoice_no"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                        Row {
                            CareInfo("Date", careDate(d.str("invoice_date")), Modifier.weight(1f))
                            CareInfo("Net", careMoney(if (d.has("net")) d.amount("net") else d.amount("net_total")), Modifier.weight(1f))
                            if (!wholesale) CareInfo("Paid", careMoney(d.amount("paid")), Modifier.weight(1f))
                            CareInfo("Balance", careMoney(d.amount("balance")), Modifier.weight(1f))
                        }
                        if (wholesale && canEdit) {
                            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                SalesFormField(
                                    "Pay now",
                                    d.amount("payment_amount").let { if (it == 0.0) "" else if (it % 1.0 == 0.0) it.toLong().toString() else it.toString() },
                                    { v ->
                                        if (v.isEmpty() || Regex("""^\d{0,9}(\.\d{0,2})?$""").matches(v)) {
                                            var pay = v.toDoubleOrNull() ?: 0.0
                                            val bal = d.amount("balance")
                                            if (pay < 0) pay = 0.0
                                            if (pay > bal) pay = bal
                                            d.addProperty("payment_amount", payR2(pay))
                                            d.addProperty("balance_amount", payR2(bal - pay))
                                            details[i] = d
                                        }
                                    },
                                    Modifier.weight(1f),
                                    keyboardType = KeyboardType.Decimal,
                                )
                                CareInfo("After", careMoney(d.amount("balance_amount")), Modifier.weight(1f))
                            }
                        }
                    }
                }
            }
            if (partyHistory.isNotEmpty()) {
                item {
                    Text("Recent payments for this customer", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate, modifier = Modifier.padding(top = 4.dp))
                }
                items(partyHistory.take(10), key = { "h-${it.jInt("id")}" }) { h ->
                    Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder)) {
                        Row(Modifier.padding(8.dp)) {
                            CareInfo("Bill", h.str("bill_no"), Modifier.weight(1f))
                            CareInfo("Paid", careMoney(h.amount("paid")), Modifier.weight(1f))
                            CareInfo("Mode", h.str("mode"), Modifier.weight(1f))
                            CareInfo("Date", careDate(h.str("entry_date")), Modifier.weight(1f))
                        }
                    }
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PharmaOutlinedButton("Close", Modifier.weight(1f), onClick = onClose)
            if (canEdit) {
                PharmaPrimaryButton(if (saving) "Saving…" else "Save", Modifier.weight(1.3f), enabled = !saving) {
                    err = null
                    if (wholesale) {
                        for (d in details) {
                            if (d.amount("payment_amount") > 0) {
                                d.addProperty("payment_mode", mode)
                                d.addProperty("transaction_no", txn)
                            }
                        }
                        val body = JsonObject().apply {
                            addProperty("patient_name", party.str("patient_name"))
                            addProperty("contact_no", party.str("contact_no"))
                            addProperty("mode", mode)
                            addProperty("transaction_no", txn)
                            add("details", JsonArray().apply { details.forEach { add(it) } })
                        }
                        saving = true
                        scope.launch {
                            try {
                                runCatching { withContext(Dispatchers.IO) { repo.settleWholesaleCustomer(body) } }
                                    .onSuccess { onSaved(it) }
                                    .onFailure { e -> err = careErr(e, "Save failed."); onError(err!!) }
                            } finally {
                                saving = false
                            }
                        }
                    } else {
                        var amt = amountText.toDoubleOrNull() ?: 0.0
                        if (amt <= 0) { err = "Enter payment amount."; return@PharmaPrimaryButton }
                        if (amt > max) amt = max
                        val body = JsonObject().apply {
                            addProperty("patient_name", party.str("patient_name"))
                            addProperty("contact_no", party.str("contact_no"))
                            addProperty("amount", amt)
                            addProperty("mode", mode)
                            addProperty("transaction_no", txn)
                        }
                        saving = true
                        scope.launch {
                            try {
                                runCatching { withContext(Dispatchers.IO) { repo.settleCustomer(body) } }
                                    .onSuccess { onSaved(it) }
                                    .onFailure { e -> err = careErr(e, "Save failed."); onError(err!!) }
                            } finally {
                                saving = false
                            }
                        }
                    }
                }
            }
        }
    }
}

// ───────────────────────── Vouchers ─────────────────────────

@Composable
private fun VouchersTab(vm: AppViewModel) {
    val repo = remember { vm.payments() }
    val scope = rememberCoroutineScope()
    val canEdit = vm.canEditModule("PAYMENTS")
    val canDelete = vm.hasFullAccess() || vm.canDelete("PAYMENTS")
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }
    var showForm by remember { mutableStateOf(false) }
    var confirmDel by remember { mutableStateOf<Int?>(null) }
    var type by remember { mutableStateOf("receipt") }
    var date by remember { mutableStateOf(LocalDate.now().toString()) }
    var partyType by remember { mutableStateOf("customer") }
    var partyName by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("cash") }
    var refNo by remember { mutableStateOf("") }
    var narration by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }

    fun load() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.vouchers(search.trim()) } }
                .onSuccess { rows = it }
                .onFailure { error = careErr(it, "Failed to load vouchers.") }
            loading = false
        }
    }
    LaunchedEffect(search) {
        if (search.isNotEmpty()) delay(400)
        load()
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it },
                placeholder = "Search vouchers…",
                modifier = Modifier.weight(1f),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
            if (canEdit) {
                PharmaPrimaryButton(if (showForm) "Cancel" else "+ New", Modifier.heightIn(max = 34.dp)) { showForm = !showForm }
            }
        }
        if (showForm && canEdit) {
            Surface(shape = RoundedCornerShape(10.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder)) {
                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        POptionDropdown("Type", type, listOf("receipt", "payment"), { type = it }, Modifier.weight(1f), display = { if (it == "receipt") "Receipt (money in)" else if (it == "payment") "Payment (money out)" else it })
                        PDateField("Date", date, { if (it.isNotBlank()) date = it }, Modifier.weight(1f))
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        POptionDropdown("Party Type", partyType, listOf("customer", "supplier", "other"), { partyType = it }, Modifier.weight(1f), display = { it.replaceFirstChar { c -> c.uppercase() } })
                        POptionDropdown("Mode", mode, listOf("cash", "card", "upi", "bank", "cheque"), { mode = it }, Modifier.weight(1f), display = { it.replaceFirstChar { c -> c.uppercase() } })
                    }
                    SalesFormField("Party Name *", partyName, { partyName = it.uppercase() }, Modifier.fillMaxWidth())
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        SalesFormField("Amount *", amount, { v -> if (v.isEmpty() || Regex("""^\d{0,9}(\.\d{0,2})?$""").matches(v)) amount = v }, Modifier.weight(1f), keyboardType = KeyboardType.Decimal)
                        SalesFormField("Ref No", refNo, { refNo = it }, Modifier.weight(1f))
                    }
                    SalesFormField("Narration", narration, { narration = it }, Modifier.fillMaxWidth())
                    PharmaPrimaryButton(if (saving) "Saving…" else "Save Voucher", Modifier.fillMaxWidth(), enabled = !saving) {
                        when {
                            partyName.isBlank() || (amount.toDoubleOrNull() ?: 0.0) <= 0 -> error = "Party name and amount are required."
                            else -> {
                                saving = true
                                val body = JsonObject().apply {
                                    addProperty("voucher_type", type)
                                    addProperty("voucher_date", date)
                                    addProperty("party_type", partyType)
                                    addProperty("party_name", partyName.trim())
                                    addProperty("amount", amount.toDouble())
                                    addProperty("mode", mode)
                                    addProperty("ref_no", refNo)
                                    addProperty("narration", narration)
                                }
                                scope.launch {
                                    try {
                                        runCatching { withContext(Dispatchers.IO) { repo.saveVoucher(body) } }
                                            .onSuccess {
                                                toast = "Voucher saved."
                                                showForm = false
                                                partyName = ""; amount = ""; refNo = ""; narration = ""
                                                load()
                                            }
                                            .onFailure { error = careErr(it, "Save failed.") }
                                    } finally {
                                        saving = false
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        when {
            loading -> Box(Modifier.weight(1f)) { LoadingBlock() }
            rows.isEmpty() -> CareEmpty(Icons.Default.ReceiptLong, "No vouchers.", Modifier.weight(1f))
            else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(rows, key = { it.jInt("id") }) { v ->
                    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                        Row(Modifier.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    Text(v.str("voucher_no"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                                    CareBadge(v.str("voucher_type").ifBlank { "—" }, if (v.str("voucher_type") == "receipt") Color(0xFF15803D) else Color(0xFFD97706))
                                }
                                Text(v.str("party_name"), style = MaterialTheme.typography.bodySmall, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text("${careDate(v.str("voucher_date"))} · ${v.str("mode")}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            }
                            Text(careMoney(v.amount("amount")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
                            if (canDelete) {
                                IconButton(onClick = { confirmDel = v.jInt("id") }, Modifier.size(34.dp)) {
                                    Icon(Icons.Default.Delete, "Delete", Modifier.size(18.dp), tint = Color(0xFFDC2626))
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    confirmDel?.let { id ->
        CareConfirmDialog(
            title = "Delete voucher?",
            text = "This cannot be undone.",
            confirmLabel = "Delete",
            danger = true,
            onConfirm = {
                confirmDel = null
                scope.launch {
                    runCatching { withContext(Dispatchers.IO) { repo.deleteVoucher(id) } }
                        .onSuccess { toast = "Voucher deleted."; load() }
                        .onFailure { error = careErr(it, "Delete failed.") }
                }
            },
            onDismiss = { confirmDel = null },
        )
    }
}

// ───────────────────────── shared cards ─────────────────────────

@Composable
private fun DuePartyCard(
    name: String,
    contact: String,
    net: Double,
    bills: Int,
    from: String,
    balance: Double,
    onOpen: () -> Unit,
) {
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth().clickable(onClick = onOpen)) {
        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(name, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (contact.isNotBlank()) Text(contact, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
                Icon(Icons.Default.ChevronRight, null, Modifier.size(20.dp), tint = PharmaMuted)
            }
            Row {
                CareInfo("Total Due", careMoney(net), Modifier.weight(1f))
                CareInfo("Bills", bills.toString(), Modifier.weight(0.7f))
                if (from.isNotBlank()) CareInfo("Due From", careDate(from), Modifier.weight(1f))
                CareInfo("Balance", careMoney(balance), Modifier.weight(1f), valueColor = Color(0xFFDC2626))
            }
        }
    }
}

@Composable
private fun HistoryCard(h: JsonObject, showDistributor: Boolean, onPrint: (() -> Unit)? = null) {
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
        Row(Modifier.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(
                    if (showDistributor) h.str("distributor_name") else (h.str("patient_name").ifBlank { h.str("party_name") }),
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.SemiBold,
                    color = PharmaSlate,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    "Bill ${h.str("bill_no")} · ${careDate(h.str("bill_date"))} · ${h.str("mode")}",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
                Text(
                    "Paid ${careMoney(h.amount("paid"))} · Rem ${careMoney(h.amount("remaining"))} · ${careDate(h.str("entry_date"))}",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaSlate,
                )
            }
            onPrint?.let {
                IconButton(onClick = it, Modifier.size(34.dp)) { Icon(Icons.Default.Print, "Print", Modifier.size(18.dp), tint = PharmaTeal) }
            }
        }
    }
}
