package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.activity.compose.BackHandler
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.transaction.ReturnsRepository
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate

// ───────────────────────── helpers ─────────────────────────

private fun rnR2(v: Double): Double = Math.round(v * 100) / 100.0

/** First non-blank primitive value among [keys]. */
private fun rnS(o: JsonObject, vararg keys: String): String {
    for (k in keys) {
        val e = o.get(k) ?: continue
        if (e.isJsonPrimitive) e.asString.trim().takeIf { it.isNotEmpty() }?.let { return it }
    }
    return ""
}

/** First non-zero number among [keys] (mirrors web `a || b`). */
private fun rnD(o: JsonObject, vararg keys: String): Double {
    for (k in keys) o.amount(k).takeIf { it != 0.0 }?.let { return it }
    return 0.0
}

private fun rnMoney(v: Double): String = rptMoney(v)

private fun rnQty(v: Double): String = if (v % 1.0 == 0.0) v.toLong().toString() else rptMoney(v)

/** Pack expiry as MM/yy. */
private fun rnExp(raw: String): String {
    val m = Regex("""^(\d{4})-(\d{2})""").find(raw.trim()) ?: return raw.ifBlank { "—" }
    return "${m.groupValues[2]}/${m.groupValues[1].takeLast(2)}"
}

private fun rnStatus(n: JsonObject): String {
    val s = rnS(n, "deduction_status").ifBlank { "Open" }
    return if (s == "Applied" || s == "Cleared") "Cleared" else s
}

private fun rnStatusColor(s: String): Color = if (s == "Cleared") Color(0xFF64748B) else Color(0xFF15803D)

private fun rnFileName(prefix: String, no: String): String = prefix + "_" + no.replace(Regex("[^A-Za-z0-9_-]"), "_") + ".pdf"

private data class RnCalc(val taxable: Double, val tax: Double, val cgst: Double, val sgst: Double, val igst: Double, val net: Double)

/** Taxable/tax split like web recalc(): intra-state (or unknown state) → CGST+SGST, else IGST. */
private fun rnCalc(rate: Double, qty: Double, dedPct: Double, gst: Double, phState: String, partyState: String): RnCalc {
    val taxable = rnR2(rate * qty * (100 - dedPct) / 100)
    val tax = rnR2(taxable * (gst / 100))
    val ph = phState.trim()
    val party = partyState.trim()
    val same = ph.isNotEmpty() && party.isNotEmpty() && ph.equals(party, ignoreCase = true)
    return if (same || ph.isEmpty() || party.isEmpty()) {
        val c = rnR2(tax / 2)
        RnCalc(taxable, tax, c, rnR2(tax - c), 0.0, rnR2(taxable + tax))
    } else {
        RnCalc(taxable, tax, 0.0, 0.0, tax, rnR2(taxable + tax))
    }
}

private fun JsonObject.putCalc(c: RnCalc) {
    addProperty("taxable", c.taxable)
    addProperty("tax", c.tax)
    addProperty("cgst", c.cgst)
    addProperty("sgst", c.sgst)
    addProperty("igst", c.igst)
    addProperty("netTotal", c.net)
}

private fun JsonObject.putOrNull(key: String, v: String) {
    if (v.isBlank()) add(key, JsonNull.INSTANCE) else addProperty(key, v)
}

private enum class RnTab(val label: String, val subtitle: String) {
    Debit("Debit Note", "Purchase return to distributor (Debit Note)"),
    SalesReturn("Retail Sales Return", "Return products from a retail bill (stock in)"),
    Credit("Credit Note", "Wholesale customer return → open credit note → deduct on next bill"),
    Deduction("Deduction %", "Months before expiry → auto deduction on wholesale credit notes"),
}

private enum class RnKind { Debit, Credit }

private data class RnFormTarget(val note: JsonObject?)

// ───────────────────────── module ─────────────────────────

@Composable
fun ReturnNoteModuleScreen(vm: AppViewModel, onBack: () -> Unit) {
    val repo = remember { vm.returns() }
    val canEdit = vm.canEditModule("RETURN_NOTE")
    var tab by rememberSaveable { mutableStateOf(RnTab.Debit) }
    var formBack by remember { mutableStateOf<(() -> Unit)?>(null) }
    var phState by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { phState = withContext(Dispatchers.IO) { repo.pharmacyState() } }

    BackHandler(enabled = formBack != null) { formBack?.invoke() }
    val onForm: ((() -> Unit)?) -> Unit = { formBack = it }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PoHeader("Return Note", tab.subtitle, { formBack?.invoke() ?: onBack() })
        if (formBack == null) {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                items(RnTab.entries.toList()) { t -> PoChip(t.label, tab == t) { tab = t } }
            }
        }
        Box(Modifier.weight(1f)) {
            when (tab) {
                RnTab.Debit -> RnNotesTab(RnKind.Debit, repo, canEdit, phState, onForm)
                RnTab.SalesReturn -> SalesReturnTab(repo, canEdit, onForm)
                RnTab.Credit -> RnNotesTab(RnKind.Credit, repo, canEdit, phState, onForm)
                RnTab.Deduction -> DeductionTab(repo, canEdit)
            }
        }
    }
}

// ───────────────────────── Debit / Credit note lists ─────────────────────────

@Composable
private fun RnNotesTab(kind: RnKind, repo: ReturnsRepository, canEdit: Boolean, phState: String, onForm: ((() -> Unit)?) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val isDebit = kind == RnKind.Debit
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var viewing by remember { mutableStateOf<JsonObject?>(null) }
    var form by remember { mutableStateOf<RnFormTarget?>(null) }
    var busy by remember { mutableStateOf(false) }

    fun load() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { if (isDebit) repo.debitNotes(search.trim()) else repo.creditNotes(search.trim()) } }
                .onSuccess { rows = it }
                .onFailure { error = careErr(it, if (isDebit) "Failed to load return notes." else "Failed to load credit notes.") }
            loading = false
        }
    }

    fun full(n: JsonObject, then: (JsonObject) -> Unit) {
        busy = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { if (isDebit) repo.debitNote(n.jInt("id")) else repo.creditNote(n.jInt("id")) } }
                .onSuccess(then)
                .onFailure { error = careErr(it, "Failed to load note.") }
            busy = false
        }
    }

    fun print(n: JsonObject) {
        busy = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { if (isDebit) repo.debitPdf(n.jInt("id")) else repo.creditPdf(n.jInt("id")) } }
                .onSuccess { bytes ->
                    careOpenPdf(context, bytes, rnFileName(if (isDebit) "debit_note" else "credit_note", rnS(n, "note_no", "order_no").ifBlank { n.jInt("id").toString() }))
                        ?.let { error = it }
                }
                .onFailure { error = careErr(it, "Failed to open print PDF.") }
            busy = false
        }
    }

    fun edit(n: JsonObject) {
        if (rnStatus(n) == "Cleared") { error = "Cleared notes cannot be edited."; return }
        full(n) { viewing = null; form = RnFormTarget(it) }
    }

    LaunchedEffect(search) {
        if (search.isNotEmpty()) delay(400)
        load()
    }
    DisposableEffect(form) {
        onForm(if (form != null) ({ form = null }) else null)
        onDispose { onForm(null) }
    }

    val target = form
    if (target != null) {
        val done: (String) -> Unit = { msg -> form = null; toast = msg; load() }
        if (isDebit) DebitNoteForm(repo, phState, target.note, { form = null }, done)
        else CreditNoteForm(repo, phState, target.note, { form = null }, done)
        return
    }

    val open = rows.filter { rnStatus(it) == "Open" }
    val shown = if (status.isBlank()) rows else rows.filter { rnStatus(it) == status }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }
        CareKpiGrid(
            listOf(
                CareKpiItem(if (isDebit) "Return Notes" else "Credit Notes", rows.size.toString(), PharmaTeal, Icons.Default.Description, status.isBlank()) { status = "" },
                CareKpiItem("Open", open.size.toString(), Color(0xFF15803D), Icons.Default.LockOpen, status == "Open") { status = if (status == "Open") "" else "Open" },
                CareKpiItem("Open Value", careMoney(open.sumOf { rnD(it, "netTotal", "amount") }), Color(0xFFD97706), Icons.Default.CurrencyRupee),
                CareKpiItem("Cleared", (rows.size - open.size).toString(), Color(0xFF64748B), Icons.Default.TaskAlt, status == "Cleared") { status = if (status == "Cleared") "" else "Cleared" },
            ),
        )
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it },
                placeholder = if (isDebit) "Search note no / distributor…" else "Search note / customer…",
                modifier = Modifier.weight(1f),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
            IconButton(onClick = { load() }, Modifier.size(34.dp)) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
            if (canEdit) PharmaPrimaryButton("+ New", Modifier.heightIn(max = 34.dp)) { form = RnFormTarget(null) }
        }
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = PharmaTeal)
        when {
            loading -> Box(Modifier.weight(1f)) { LoadingBlock(if (isDebit) "Loading return notes…" else "Loading credit notes…") }
            shown.isEmpty() -> CareEmpty(Icons.Default.Inventory2, if (isDebit) "No return notes yet." else "No credit notes yet.", Modifier.weight(1f))
            else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(shown, key = { it.jInt("id") }) { n ->
                    RnNoteCard(
                        n = n,
                        isDebit = isDebit,
                        canEdit = canEdit,
                        onView = { full(n) { viewing = it } },
                        onPrint = { print(n) },
                        onEdit = { edit(n) },
                    )
                }
            }
        }
    }

    viewing?.let { note ->
        RnNoteSheet(
            note = note,
            isDebit = isDebit,
            canEdit = canEdit,
            onPrint = { print(note) },
            onEdit = { edit(note) },
            onDismiss = { viewing = null },
        )
    }
}

@Composable
private fun RnNoteCard(n: JsonObject, isDebit: Boolean, canEdit: Boolean, onView: () -> Unit, onPrint: () -> Unit, onEdit: () -> Unit) {
    val st = rnStatus(n)
    val party = if (isDebit) rnS(n, "distributor_name", "party_name") else rnS(n, "customer_name", "party_name")
    val applied = if (isDebit) rnS(n, "applied_po_no") else rnS(n, "applied_sale_no")
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth().clickable(onClick = onView)) {
        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(rnS(n, "note_no", "order_no"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                Spacer(Modifier.width(8.dp))
                Text(careDate(rnS(n, "note_date", "order_date")), style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                Spacer(Modifier.weight(1f))
                CareBadge(st, rnStatusColor(st))
            }
            Text(party.ifBlank { "—" }, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
            val contact = rnS(n, "d_cont")
            if (contact.isNotBlank() || applied.isNotBlank()) {
                Text(
                    listOfNotNull(contact.takeIf { it.isNotBlank() }, applied.takeIf { it.isNotBlank() }?.let { if (isDebit) "PO: $it" else "Bill: $it" }).joinToString(" · "),
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                CareInfo("Taxable", rnMoney(n.amount("taxable")), Modifier.weight(1f))
                CareInfo("Tax", rnMoney(rnD(n, "tax", "gst_amount")), Modifier.weight(1f))
                CareInfo("Net", careMoney(rnD(n, "netTotal", "amount")), Modifier.weight(1.2f), valueColor = PharmaTealDark)
                IconButton(onClick = onPrint, Modifier.size(32.dp)) { Icon(Icons.Default.Print, "Print", Modifier.size(18.dp), tint = PharmaSlate) }
                if (st == "Open" && canEdit) IconButton(onClick = onEdit, Modifier.size(32.dp)) { Icon(Icons.Default.Edit, "Edit", Modifier.size(18.dp), tint = PharmaTeal) }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun RnNoteSheet(note: JsonObject, isDebit: Boolean, canEdit: Boolean, onPrint: () -> Unit, onEdit: () -> Unit, onDismiss: () -> Unit) {
    val st = rnStatus(note)
    val lines = note.arr("products").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Color.White) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 14.dp).padding(bottom = 18.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(rnS(note, "note_no", "order_no"), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = PharmaSlate)
                    Text(careDate(rnS(note, "note_date", "order_date")), style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                }
                CareBadge(st, rnStatusColor(st))
            }
            Row {
                CareInfo(if (isDebit) "Distributor" else "Customer", if (isDebit) rnS(note, "distributor_name", "party_name") else rnS(note, "customer_name", "party_name"), Modifier.weight(1.4f))
                CareInfo("Contact", rnS(note, "d_cont"), Modifier.weight(1f))
            }
            Row {
                CareInfo("GSTIN", rnS(note, "d_gst"), Modifier.weight(1.4f))
                CareInfo("State", rnS(note, "state"), Modifier.weight(1f))
            }
            val applied = if (isDebit) rnS(note, "applied_po_no") else rnS(note, "applied_sale_no")
            if (applied.isNotBlank()) CareInfo(if (isDebit) "Applied on PO" else "Applied on bill", applied)

            Text("Products (${lines.size})", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            lines.forEach { p ->
                Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Text(rnS(p, "p_name", "product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Text(
                            "Batch ${rnS(p, "batch_no").ifBlank { "—" }} · Exp ${rnExp(rnS(p, "exp_date"))} · ${rnS(p, "reason").ifBlank { "—" }}",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                        )
                        Row {
                            if (isDebit) {
                                CareInfo("Stock", rnQty(rnD(p, "saleQty", "stock_qty")), Modifier.weight(1f))
                                CareInfo("Rate", rnMoney(p.amount("net_rate")), Modifier.weight(1f))
                            } else {
                                CareInfo("Sale Qty", rnQty(p.amount("saleQty")), Modifier.weight(1f))
                                CareInfo("Rate", rnMoney(p.amount("sale_rate")), Modifier.weight(1f))
                                CareInfo("Ded %", rnQty(rnD(p, "diduction", "deduction_pct")), Modifier.weight(0.8f))
                            }
                            CareInfo("Return", rnQty(p.amount("return_qty")), Modifier.weight(0.8f))
                        }
                        Row {
                            CareInfo("Taxable", rnMoney(p.amount("taxable")), Modifier.weight(1f))
                            CareInfo("Tax", rnMoney(p.amount("tax")), Modifier.weight(1f))
                            CareInfo("Net", rnMoney(p.amount("netTotal")), Modifier.weight(1f), valueColor = PharmaTealDark)
                        }
                    }
                }
            }
            HorizontalDivider(color = PharmaBorder)
            Row {
                CareInfo("Taxable", rnMoney(note.amount("taxable")), Modifier.weight(1f))
                CareInfo("Tax", rnMoney(rnD(note, "tax", "gst_amount")), Modifier.weight(1f))
                CareInfo("Net", careMoney(rnD(note, "netTotal", "amount")), Modifier.weight(1f), valueColor = PharmaTealDark)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                PharmaOutlinedButton("Print", Modifier.weight(1f), onClick = onPrint)
                if (st == "Open" && canEdit) PharmaPrimaryButton("Edit", Modifier.weight(1f), onClick = onEdit)
            }
        }
    }
}

// ───────────────────────── shared form pieces ─────────────────────────

@Composable
private fun RnPickField(label: String, value: String, placeholder: String, modifier: Modifier = Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Column(modifier) {
        Text(label, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        Surface(
            shape = RoundedCornerShape(6.dp),
            color = if (enabled) Color.White else Color(0xFFF1F5F9),
            border = BorderStroke(1.dp, PharmaBorder),
            modifier = Modifier.fillMaxWidth().height(34.dp).clickable(enabled = enabled, onClick = onClick),
        ) {
            Row(Modifier.padding(horizontal = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.Search, null, Modifier.size(15.dp), tint = PharmaMuted)
                Spacer(Modifier.width(6.dp))
                Text(
                    value.ifBlank { placeholder },
                    Modifier.weight(1f),
                    style = MaterialTheme.typography.bodySmall,
                    color = if (value.isBlank()) PharmaMuted else PharmaSlate,
                    fontWeight = if (value.isBlank()) FontWeight.Normal else FontWeight.SemiBold,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Icon(Icons.Default.ArrowDropDown, null, Modifier.size(18.dp), tint = PharmaMuted)
            }
        }
    }
}

@Composable
private fun RnPickerDialog(
    title: String,
    options: List<Pair<String, String>>,
    loading: Boolean,
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
                    placeholder = "Search…",
                    modifier = Modifier.fillMaxWidth(),
                    leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
                )
                when {
                    loading -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp) }
                    shown.isEmpty() -> Text(if (options.isEmpty()) emptyText else "No match", style = MaterialTheme.typography.bodySmall, color = PharmaMuted, modifier = Modifier.padding(vertical = 16.dp))
                    else -> LazyColumn(Modifier.weight(1f, fill = false)) {
                        items(shown) { (v, l) ->
                            Text(
                                l,
                                style = MaterialTheme.typography.bodySmall,
                                color = PharmaSlate,
                                modifier = Modifier.fillMaxWidth().clickable { onPick(v) }.padding(horizontal = 6.dp, vertical = 9.dp),
                            )
                            HorizontalDivider(color = PharmaBorder)
                        }
                    }
                }
                if (q.isBlank() && options.size > 80) Text("Showing 80 of ${options.size} — type to search.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { TextButton(onClick = onDismiss) { Text("Close") } }
            }
        }
    }
}

@Composable
private fun RnLineCard(p: JsonObject, isDebit: Boolean, onRemove: () -> Unit) {
    Surface(shape = RoundedCornerShape(8.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(start = 8.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(rnS(p, "p_name", "product_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(
                    buildString {
                        append("Batch ${rnS(p, "batch_no").ifBlank { "—" }}")
                        if (isDebit) rnS(p, "distributor_name").takeIf { it.isNotBlank() }?.let { append(" · $it") }
                        else append(" · Ded ${rnQty(p.amount("diduction"))}%")
                        append(" · ${rnS(p, "reason")}")
                    },
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    "Qty ${rnQty(p.amount("return_qty"))} · Taxable ${rnMoney(p.amount("taxable"))} · Tax ${rnMoney(p.amount("tax"))}",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaSlate,
                )
            }
            Text(rnMoney(p.amount("netTotal")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaTealDark)
            IconButton(onClick = onRemove, Modifier.size(34.dp)) { Icon(Icons.Default.Delete, "Remove", Modifier.size(18.dp), tint = Color(0xFFDC2626)) }
        }
    }
}

@Composable
private fun RnLinesBlock(lines: List<JsonObject>, isDebit: Boolean, onRemove: (Int) -> Unit) {
    Text("Return lines (${lines.size})", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
    if (lines.isEmpty()) {
        Text("No lines added yet.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted, modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp), textAlign = TextAlign.Center)
        return
    }
    lines.forEachIndexed { i, p -> key(i, rnS(p, "batch_no"), rnS(p, "p_name")) { RnLineCard(p, isDebit) { onRemove(i) } } }
    Surface(shape = RoundedCornerShape(8.dp), color = PharmaTeal.copy(alpha = 0.06f), modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(8.dp)) {
            CareInfo("Taxable", rnMoney(lines.sumOf { it.amount("taxable") }), Modifier.weight(1f))
            CareInfo("Tax", rnMoney(lines.sumOf { it.amount("tax") }), Modifier.weight(1f))
            CareInfo("Net Total", careMoney(lines.sumOf { it.amount("netTotal") }), Modifier.weight(1f), valueColor = PharmaTealDark)
        }
    }
}

@Composable
private fun RnFormBar(title: String, subtitle: String?, onCancel: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        IconButton(onClick = onCancel, Modifier.size(32.dp)) { Icon(Icons.Default.ArrowBack, "Back to list", tint = PharmaSlate) }
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
            subtitle?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = PharmaMuted) }
        }
    }
}

// ───────────────────────── Debit note form ─────────────────────────

private val DebitReasons = listOf("Expiry", "Damage", "Excess Stock", "Near Expiry", "Wrong Supply", "Other")

@Composable
private fun DebitNoteForm(repo: ReturnsRepository, phState: String, edit: JsonObject?, onCancel: () -> Unit, onSaved: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val editId = edit?.jInt("id")
    val editNo = edit?.let { rnS(it, "note_no", "order_no") } ?: ""

    var error by remember { mutableStateOf<String?>(null) }
    var products by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var productsLoading by remember { mutableStateOf(true) }
    var picker by remember { mutableStateOf(false) }

    var pName by remember { mutableStateOf("") }
    var batches by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var batchesLoading by remember { mutableStateOf(false) }
    var batch by remember { mutableStateOf<JsonObject?>(null) }
    var suppliers by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var suppliersLoading by remember { mutableStateOf(false) }
    var supplier by remember { mutableStateOf<JsonObject?>(null) }
    var rate by remember { mutableDoubleStateOf(0.0) }
    var gst by remember { mutableDoubleStateOf(0.0) }
    var qtyText by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("Expiry") }
    val noteParty = remember { edit?.let { rnS(it, "distributor_name", "party_name") } ?: "" }
    var lines by remember { mutableStateOf<List<JsonObject>>(edit?.arr("products")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject?.deepCopy() } ?: emptyList()) }
    var saving by remember { mutableStateOf(false) }

    val stock = batch?.let { if (it.has("stock_qty") && !it.get("stock_qty").isJsonNull) it.amount("stock_qty") else it.amount("wAvailableQty") } ?: 0.0
    val qty = qtyText.toDoubleOrNull() ?: 0.0
    val partyState = supplier?.let { rnS(it, "state") } ?: ""
    val calc = rnCalc(rate, qty, 0.0, gst, phState, partyState)

    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { repo.debitProducts() } }
            .onSuccess { products = it }
            .onFailure { products = emptyList() }
        productsLoading = false
    }

    fun applySupplier(s: JsonObject) {
        supplier = s
        s.amount("purchase_rate").takeIf { it > 0 }?.let { rate = it }
        s.amount("gst_rate").takeIf { it > 0 }?.let { gst = it }
    }

    fun selectBatch(b: JsonObject) {
        batch = b
        rate = b.amount("net_rate")
        gst = b.amount("gst_per")
        supplier = null
        suppliers = emptyList()
        suppliersLoading = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.debitSuppliers(pName, rnS(b, "batch_no")) } }
                .onSuccess { s ->
                    suppliers = s
                    when {
                        s.size == 1 -> applySupplier(s[0])
                        noteParty.isNotBlank() -> s.firstOrNull { rnS(it, "distributor_name") == noteParty }?.let { applySupplier(it) }
                    }
                }
                .onFailure { error = careErr(it, "Failed to load distributors.") }
            suppliersLoading = false
        }
    }

    fun pickProduct(name: String) {
        picker = false
        pName = name
        batch = null
        batches = emptyList()
        suppliers = emptyList()
        supplier = null
        qtyText = ""
        batchesLoading = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.debitBatches(name) } }
                .onSuccess { b ->
                    batches = b
                    if (b.size == 1) selectBatch(b[0])
                }
                .onFailure { error = careErr(it, "Failed to load batches.") }
            batchesLoading = false
        }
    }

    fun resetLine() {
        pName = ""
        batch = null
        batches = emptyList()
        suppliers = emptyList()
        supplier = null
        rate = 0.0
        gst = 0.0
        qtyText = ""
        reason = "Expiry"
    }

    fun addLine() {
        error = null
        val b = batch
        val s = supplier
        if (pName.isBlank() || b == null || s == null) { error = "Select product, batch and distributor."; return }
        if (qty <= 0) { error = "Enter return qty."; return }
        val line = JsonObject().apply {
            addProperty("p_name", pName)
            addProperty("batch_no", rnS(b, "batch_no"))
            addProperty("batch_id", b.jInt("batch_id"))
            addProperty("product_binding_id", s.jInt("product_binding_id").takeIf { it > 0 } ?: b.jInt("product_binding_id"))
            addProperty("saleQty", stock)
            addProperty("net_rate", rate)
            addProperty("gst_per", gst)
            putOrNull("exp_date", rnS(b, "expiry", "exp_date"))
            addProperty("return_qty", qty)
            addProperty("reason", reason)
            putCalc(calc)
            addProperty("invoice_no", rnS(s, "invoice_no"))
            putOrNull("invoice_date", rnS(s, "invoice_date"))
            addProperty("hsn", rnS(b, "hsn"))
            addProperty("pack_size", rnS(b, "pack_size"))
            addProperty("po_no", rnS(s, "po_no"))
            addProperty("purchase_id", s.jInt("purchase_id"))
            addProperty("distributor_name", rnS(s, "distributor_name"))
            addProperty("state", rnS(s, "state"))
            addProperty("d_cont", rnS(s, "d_cont"))
            addProperty("d_mail", rnS(s, "d_mail"))
            addProperty("d_gst", rnS(s, "d_gst"))
            addProperty("d_add", rnS(s, "d_add"))
            addProperty("d_no", rnS(s, "d_no", "distributor_id"))
        }
        lines = lines + line
        resetLine()
    }

    fun save() {
        error = null
        if (lines.isEmpty()) { error = "Add at least one product line."; return }
        val first = lines[0]
        val party = rnS(first, "distributor_name").ifBlank { noteParty }
        val body = JsonObject().apply {
            addProperty("distributor_name", party)
            addProperty("party_name", party)
            addProperty("state", rnS(first, "state").ifBlank { edit?.let { rnS(it, "state") } ?: "" })
            addProperty("d_cont", rnS(first, "d_cont").ifBlank { edit?.let { rnS(it, "d_cont") } ?: "" })
            addProperty("d_mail", rnS(first, "d_mail").ifBlank { edit?.let { rnS(it, "d_mail") } ?: "" })
            addProperty("d_gst", rnS(first, "d_gst").ifBlank { edit?.let { rnS(it, "d_gst") } ?: "" })
            addProperty("d_add", rnS(first, "d_add"))
            addProperty("d_no", rnS(first, "d_no"))
            addProperty("note_date", edit?.let { rnS(it, "note_date", "order_date").take(10) }?.takeIf { it.isNotBlank() } ?: LocalDate.now().toString())
            add("products", JsonArray().apply { lines.forEach { add(it) } })
        }
        saving = true
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.saveDebit(editId, body) } }
                    .onSuccess { r -> onSaved("Return note ${rnS(r, "note_no")} saved.") }
                    .onFailure { error = careErr(it, "Save failed.") }
            } finally {
                saving = false
            }
        }
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        RnFormBar(if (editId != null) "Edit Return Note" else "New Return Note", if (editId != null) "Editing $editNo — stock will be re-synced on save." else "Purchase return to distributor", onCancel)
        error?.let { ErrorBanner(it) { error = null } }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    RnPickField("Product *", pName, if (productsLoading) "Loading products…" else "Search product…", Modifier.fillMaxWidth()) { picker = true }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        POptionDropdown(
                            "Batch *",
                            batch?.let { rnS(it, "batch_no") } ?: "",
                            batches.map { rnS(it, "batch_no") },
                            { bn -> batches.firstOrNull { rnS(it, "batch_no") == bn }?.let { selectBatch(it) } },
                            Modifier.weight(1f),
                            display = { bn ->
                                if (bn.isBlank()) (if (batchesLoading) "Loading…" else if (batches.isEmpty()) "—" else "Select…")
                                else batches.firstOrNull { rnS(it, "batch_no") == bn }?.let { "$bn (${rnQty(if (it.has("stock_qty")) it.amount("stock_qty") else it.amount("wAvailableQty"))})" } ?: bn
                            },
                        )
                        POptionDropdown(
                            "Distributor *",
                            supplier?.let { rnS(it, "distributor_name") } ?: "",
                            suppliers.map { rnS(it, "distributor_name") },
                            { dn -> suppliers.firstOrNull { rnS(it, "distributor_name") == dn }?.let { applySupplier(it) } },
                            Modifier.weight(1.3f),
                            display = { dn -> dn.ifBlank { if (suppliersLoading) "Loading…" else if (suppliers.isEmpty()) "—" else "Select…" } },
                        )
                    }
                    if (batch != null && suppliers.isEmpty() && !suppliersLoading) {
                        Text(
                            "No purchase history for this batch — distributor list empty. Return from a purchased batch, or enter purchase first.",
                            style = MaterialTheme.typography.labelSmall,
                            color = Color(0xFFB45309),
                        )
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PReadField("Stock", rnQty(stock), Modifier.weight(1f))
                        PReadField("Rate", rnMoney(rate), Modifier.weight(1f))
                        PReadField("GST %", rnQty(gst), Modifier.weight(0.8f))
                        PReadField("Expiry", batch?.let { rnExp(rnS(it, "expiry", "exp_date")) } ?: "—", Modifier.weight(0.9f))
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PReadField("Invoice", supplier?.let { rnS(it, "invoice_no") }?.ifBlank { "—" } ?: "—", Modifier.weight(1.2f))
                        PReadField("Inv. Date", supplier?.let { careDate(rnS(it, "invoice_date")) } ?: "—", Modifier.weight(1f))
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
                        SalesFormField(
                            "Return Qty *",
                            qtyText,
                            { v ->
                                val t = v.filter { it.isDigit() }.take(6)
                                val q = t.toDoubleOrNull() ?: 0.0
                                if (q > stock) { error = "Return qty cannot be greater than stock qty."; qtyText = "" } else qtyText = t
                            },
                            Modifier.weight(0.8f),
                            keyboardType = KeyboardType.Number,
                        )
                        POptionDropdown("Reason", reason, DebitReasons, { reason = it }, Modifier.weight(1.2f))
                    }
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text("Taxable ${rnMoney(calc.taxable)} · Tax ${rnMoney(calc.tax)}" + if (calc.igst > 0) " (IGST)" else "", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            Text("Net ${careMoney(calc.net)}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                        }
                        PharmaPrimaryButton("+ Add", Modifier.heightIn(max = 34.dp)) { addLine() }
                    }
                }
            }
            RnLinesBlock(lines, isDebit = true) { i -> lines = lines.filterIndexed { idx, _ -> idx != i } }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PharmaOutlinedButton("Cancel", Modifier.weight(1f), onClick = onCancel)
            PharmaPrimaryButton(if (saving) "Saving…" else "Save Return Note", Modifier.weight(1.5f), enabled = !saving && lines.isNotEmpty()) { save() }
        }
    }

    if (picker) {
        RnPickerDialog(
            title = "Select product",
            options = products.map { rnS(it, "value") to rnS(it, "label", "value") },
            loading = productsLoading,
            emptyText = "No stocked products",
            onPick = { pickProduct(it) },
            onDismiss = { picker = false },
        )
    }
}

// ───────────────────────── Credit note form ─────────────────────────

private val CreditReasons = listOf("Expiry", "Breakages", "Non Movable", "Other")

@Composable
private fun CreditNoteForm(repo: ReturnsRepository, phState: String, edit: JsonObject?, onCancel: () -> Unit, onSaved: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val editId = edit?.jInt("id")
    val editNo = edit?.let { rnS(it, "note_no") } ?: ""

    var error by remember { mutableStateOf<String?>(null) }
    var customers by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var customersLoading by remember { mutableStateOf(true) }
    var custPicker by remember { mutableStateOf(false) }
    var customerName by remember { mutableStateOf(edit?.let { rnS(it, "customer_name", "party_name") } ?: "") }
    var customerId by remember { mutableStateOf<Int?>(null) }
    var cCont by remember { mutableStateOf(edit?.let { rnS(it, "d_cont") } ?: "") }
    var cMail by remember { mutableStateOf(edit?.let { rnS(it, "d_mail") } ?: "") }
    var cAdd by remember { mutableStateOf("") }
    var gstNo by remember { mutableStateOf(edit?.let { rnS(it, "d_gst") } ?: "") }
    var partyState by remember { mutableStateOf(edit?.let { rnS(it, "state") } ?: "") }

    var products by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var productsLoading by remember { mutableStateOf(false) }
    var prodPicker by remember { mutableStateOf(false) }
    var pName by remember { mutableStateOf("") }
    var batches by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var batchesLoading by remember { mutableStateOf(false) }
    var batch by remember { mutableStateOf<JsonObject?>(null) }
    var dedText by remember { mutableStateOf("") }
    var qtyText by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("Expiry") }
    var lines by remember { mutableStateOf<List<JsonObject>>(edit?.arr("products")?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject?.deepCopy() } ?: emptyList()) }
    var saving by remember { mutableStateOf(false) }

    val saleQty = batch?.let { rnD(it, "saleQty", "sale_qty") } ?: 0.0
    val saleRate = batch?.amount("sale_rate") ?: 0.0
    val gst = batch?.let { rnD(it, "gst", "gst_per", "gst_rate") } ?: 0.0
    val ded = dedText.toDoubleOrNull() ?: 0.0
    val qty = qtyText.toDoubleOrNull() ?: 0.0
    val calc = rnCalc(saleRate, qty, ded, gst, phState, partyState)

    fun loadProducts(cust: String) {
        productsLoading = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.creditProducts(cust) } }
                .onSuccess { products = it }
                .onFailure { products = emptyList() }
            productsLoading = false
        }
    }

    LaunchedEffect(Unit) {
        runCatching { withContext(Dispatchers.IO) { repo.creditCustomers() } }.onSuccess { customers = it }
        customersLoading = false
        if (customerName.isNotBlank()) loadProducts(customerName)
    }

    fun resetLine() {
        pName = ""
        batch = null
        batches = emptyList()
        dedText = ""
        qtyText = ""
        reason = "Expiry"
    }

    fun pickCustomer(value: String) {
        custPicker = false
        val c = customers.firstOrNull { rnS(it, "value", "customer_name", "label") == value } ?: return
        customerName = rnS(c, "value", "customer_name", "label")
        customerId = c.jInt("customer_id").takeIf { it > 0 }
        cCont = rnS(c, "c_cont", "phone")
        cMail = rnS(c, "c_mail", "email")
        cAdd = rnS(c, "c_add", "address")
        gstNo = rnS(c, "gst_no", "gstin")
        partyState = rnS(c, "state")
        resetLine()
        loadProducts(customerName)
    }

    fun selectBatch(b: JsonObject) {
        batch = b
        dedText = rnD(b, "diduction", "deduction_pct").takeIf { it != 0.0 }?.let { rnQty(it) } ?: ""
        qtyText = ""
    }

    fun pickProduct(name: String) {
        prodPicker = false
        pName = name
        batch = null
        batches = emptyList()
        batchesLoading = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.creditBatches(customerName, name) } }
                .onSuccess { b ->
                    batches = b
                    if (b.size == 1) selectBatch(b[0])
                }
                .onFailure { batches = emptyList() }
            batchesLoading = false
        }
    }

    fun addLine() {
        error = null
        val b = batch
        if (customerName.isBlank() || pName.isBlank() || b == null) { error = "Select customer, product and batch."; return }
        if (qty <= 0) { error = "Enter return qty."; return }
        val saleItemId = b.jInt("sale_item_id").takeIf { it > 0 } ?: b.jInt("id")
        val line = JsonObject().apply {
            addProperty("p_name", pName)
            addProperty("batch_no", rnS(b, "batch_no"))
            addProperty("id", saleItemId)
            addProperty("sale_item_id", saleItemId)
            addProperty("product_binding_id", b.jInt("product_binding_id"))
            addProperty("batch_id", b.jInt("batch_id"))
            addProperty("saleQty", saleQty)
            addProperty("sale_rate", saleRate)
            addProperty("gst", gst)
            addProperty("gst_per", gst)
            putOrNull("exp_date", rnS(b, "exp_date", "expiry"))
            addProperty("diduction", ded)
            addProperty("return_qty", qty)
            addProperty("reason", reason)
            putCalc(calc)
            addProperty("hsn", rnS(b, "hsn"))
            addProperty("pack_size", rnS(b, "pack_size"))
        }
        lines = lines + line
        resetLine()
    }

    fun save() {
        error = null
        if (lines.isEmpty()) { error = "Add at least one product line."; return }
        val body = JsonObject().apply {
            addProperty("customer_name", customerName)
            addProperty("party_name", customerName)
            if (customerId != null) addProperty("customer_id", customerId) else add("customer_id", JsonNull.INSTANCE)
            addProperty("c_cont", cCont)
            addProperty("c_mail", cMail)
            addProperty("c_add", cAdd)
            addProperty("gst_no", gstNo)
            addProperty("state", partyState)
            add("products", JsonArray().apply { lines.forEach { add(it) } })
        }
        saving = true
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.saveCredit(editId, body) } }
                    .onSuccess { r -> onSaved("Credit note ${rnS(r, "note_no")} saved.") }
                    .onFailure { error = careErr(it, "Save failed.") }
            } finally {
                saving = false
            }
        }
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        RnFormBar(if (editId != null) "Edit Credit Note" else "New Credit Note", if (editId != null) "Editing $editNo — stock will be re-synced on save." else "Wholesale customer return", onCancel)
        error?.let { ErrorBanner(it) { error = null } }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    RnPickField("Customer *", customerName, if (customersLoading) "Loading customers…" else "Search customer…", Modifier.fillMaxWidth()) { custPicker = true }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PReadField("Contact", cCont.ifBlank { "—" }, Modifier.weight(1f))
                        PReadField("GSTIN", gstNo.ifBlank { "—" }, Modifier.weight(1.2f))
                    }
                    if (cAdd.isNotBlank()) PReadField("Address", cAdd, Modifier.fillMaxWidth())
                }
            }
            Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    RnPickField(
                        "Product *",
                        pName,
                        when {
                            customerName.isBlank() -> "Select customer first"
                            productsLoading -> "Loading products…"
                            else -> "Search product…"
                        },
                        Modifier.fillMaxWidth(),
                        enabled = customerName.isNotBlank(),
                    ) { prodPicker = true }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        POptionDropdown(
                            "Batch *",
                            batch?.let { rnS(it, "batch_no") } ?: "",
                            batches.map { rnS(it, "batch_no") },
                            { bn -> batches.firstOrNull { rnS(it, "batch_no") == bn }?.let { selectBatch(it) } },
                            Modifier.weight(1.3f),
                            display = { bn ->
                                if (bn.isBlank()) (if (batchesLoading) "Loading…" else if (batches.isEmpty()) "—" else "Select…")
                                else batches.firstOrNull { rnS(it, "batch_no") == bn }?.let { "$bn (${rnQty(rnD(it, "saleQty", "sale_qty"))})" } ?: bn
                            },
                        )
                        PReadField("Avail", rnQty(saleQty), Modifier.weight(0.7f))
                        PReadField("Rate", rnMoney(saleRate), Modifier.weight(0.9f))
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        PReadField("GST %", rnQty(gst), Modifier.weight(1f))
                        PReadField("Expiry", batch?.let { rnExp(rnS(it, "exp_date", "expiry")) } ?: "—", Modifier.weight(1f))
                        SalesFormField(
                            "Ded %",
                            dedText,
                            { v -> if (v.isEmpty() || Regex("""^\d{0,3}(\.\d{0,2})?$""").matches(v)) { if ((v.toDoubleOrNull() ?: 0.0) <= 100) dedText = v } },
                            Modifier.weight(1f),
                            keyboardType = KeyboardType.Decimal,
                        )
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
                        SalesFormField(
                            "Return Qty *",
                            qtyText,
                            { v ->
                                val t = v.filter { it.isDigit() }.take(6)
                                val q = t.toDoubleOrNull() ?: 0.0
                                if (q > saleQty) { error = "Return qty cannot exceed sold qty available."; qtyText = "" } else qtyText = t
                            },
                            Modifier.weight(0.8f),
                            keyboardType = KeyboardType.Number,
                        )
                        POptionDropdown("Reason", reason, CreditReasons, { reason = it }, Modifier.weight(1.2f))
                    }
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text("Taxable ${rnMoney(calc.taxable)} · Tax ${rnMoney(calc.tax)}" + if (calc.igst > 0) " (IGST)" else "", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            Text("Net ${careMoney(calc.net)}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                        }
                        PharmaPrimaryButton("+ Add", Modifier.heightIn(max = 34.dp)) { addLine() }
                    }
                }
            }
            RnLinesBlock(lines, isDebit = false) { i -> lines = lines.filterIndexed { idx, _ -> idx != i } }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PharmaOutlinedButton("Cancel", Modifier.weight(1f), onClick = onCancel)
            PharmaPrimaryButton(if (saving) "Saving…" else "Save Credit Note", Modifier.weight(1.5f), enabled = !saving && lines.isNotEmpty()) { save() }
        }
    }

    if (custPicker) {
        RnPickerDialog(
            title = "Select customer",
            options = customers.map { rnS(it, "value", "customer_name", "label") to rnS(it, "label", "value", "customer_name") },
            loading = customersLoading,
            emptyText = "No wholesale customers with sales",
            onPick = { pickCustomer(it) },
            onDismiss = { custPicker = false },
        )
    }
    if (prodPicker) {
        RnPickerDialog(
            title = "Select product",
            options = products.map { rnS(it, "value") to rnS(it, "label", "value") },
            loading = productsLoading,
            emptyText = "No products",
            onPick = { pickProduct(it) },
            onDismiss = { prodPicker = false },
        )
    }
}

// ───────────────────────── Retail sales return ─────────────────────────

private fun srUnitRate(p: JsonObject): Double = rnD(p, "unit_rate", "rate")

private fun srLineAmt(p: JsonObject, qty: Double): Double {
    val disc = rnD(p, "disc_per", "discount_pct")
    return rnR2(qty * srUnitRate(p) * (100 - disc) / 100)
}

private fun srQtyUnit(p: JsonObject): String = if (p.jInt("units_per_pack") > 1) "tabs" else "units"

@Composable
private fun SalesReturnTab(repo: ReturnsRepository, canEdit: Boolean, onForm: ((() -> Unit)?) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var view by rememberSaveable { mutableStateOf("bills") }
    var bills by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var history by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }
    var hSearch by remember { mutableStateOf("") }
    var bill by remember { mutableStateOf<JsonObject?>(null) }
    var detail by remember { mutableStateOf<JsonObject?>(null) }
    var busy by remember { mutableStateOf(false) }

    fun loadBills() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.returnableSales(search.trim(), 30) } }
                .onSuccess { bills = it }
                .onFailure { error = careErr(it, "Failed to load bills.") }
            loading = false
        }
    }

    fun loadHistory() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.salesReturnHistory(hSearch.trim()) } }
                .onSuccess { history = it }
                .onFailure { error = careErr(it, "Failed to load return history.") }
            loading = false
        }
    }

    fun printReturn(id: Int, no: String) {
        busy = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.salesReturnPdf(id) } }
                .onSuccess { bytes -> careOpenPdf(context, bytes, rnFileName("sales_return", no.ifBlank { id.toString() }))?.let { error = it } }
                .onFailure { error = careErr(it, "Failed to open print PDF.") }
            busy = false
        }
    }

    fun openBill(row: JsonObject) {
        busy = true
        error = null
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.returnableSale(row.jInt("id")) } }
                .onSuccess { bill = it }
                .onFailure { error = careErr(it, "Failed to load bill.") }
            busy = false
        }
    }

    fun openHistory(row: JsonObject) {
        busy = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.salesReturnDetail(row.jInt("id")) } }
                .onSuccess { detail = it }
                .onFailure { error = careErr(it, "Failed to load return.") }
            busy = false
        }
    }

    LaunchedEffect(view, search) {
        if (view != "bills") return@LaunchedEffect
        if (search.isNotEmpty()) delay(400)
        loadBills()
    }
    LaunchedEffect(view, hSearch) {
        if (view != "history") return@LaunchedEffect
        if (hSearch.isNotEmpty()) delay(400)
        loadHistory()
    }
    DisposableEffect(bill) {
        onForm(if (bill != null) ({ bill = null }) else null)
        onDispose { onForm(null) }
    }

    bill?.let { b ->
        SalesReturnForm(
            bill = b,
            canEdit = canEdit,
            repo = repo,
            onCancel = { bill = null },
            onSaved = { id, no ->
                bill = null
                toast = "Return $no saved."
                if (id > 0) printReturn(id, no)
                loadBills()
            },
        )
        return
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            PoChip("Retail Bills", view == "bills") { view = "bills" }
            PoChip("History / Print", view == "history") { view = "history" }
            Spacer(Modifier.weight(1f))
            IconButton(onClick = { if (view == "bills") loadBills() else loadHistory() }, Modifier.size(34.dp)) { Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal) }
        }
        if (view == "bills") {
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it },
                placeholder = "Search bill / customer / doctor…",
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
            if (bills.isNotEmpty()) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SalesLogKpiChip("Bills", bills.size.toString(), Modifier.weight(1f))
                    SalesLogKpiChip("Bill value", careMoney(bills.sumOf { it.amount("net_amount") }), Modifier.weight(1.3f))
                    SalesLogKpiChip("Returned", careMoney(bills.sumOf { it.amount("return_total") }), Modifier.weight(1.3f))
                }
            }
        } else {
            SalesDenseSearchField(
                value = hSearch,
                onValueChange = { hSearch = it },
                placeholder = "Search return no / customer…",
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaMuted) },
            )
            if (history.isNotEmpty()) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SalesLogKpiChip("Returns", history.size.toString(), Modifier.weight(1f))
                    SalesLogKpiChip("Total returned", careMoney(history.sumOf { it.amount("return_total") }), Modifier.weight(1.6f))
                }
            }
        }
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = PharmaTeal)
        when {
            loading -> Box(Modifier.weight(1f)) { LoadingBlock() }
            view == "bills" && bills.isEmpty() -> CareEmpty(Icons.Default.ReceiptLong, "No retail bills found.", Modifier.weight(1f))
            view == "history" && history.isEmpty() -> CareEmpty(Icons.Default.History, "No returns yet.", Modifier.weight(1f))
            view == "bills" -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(bills, key = { it.jInt("id") }) { s -> SrBillCard(s) { openBill(s) } }
                item {
                    Text("Showing latest 30. Use search for older bills.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted, modifier = Modifier.padding(vertical = 6.dp))
                }
            }
            else -> LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(history, key = { it.jInt("id") }) { h ->
                    SrHistoryCard(h, onView = { openHistory(h) }, onPrint = { printReturn(h.jInt("id"), rnS(h, "return_no")) })
                }
            }
        }
    }

    detail?.let { d ->
        SrHistorySheet(d, onPrint = { printReturn(d.jInt("id"), rnS(d, "return_no")) }, onDismiss = { detail = null })
    }
}

@Composable
private fun SrBillCard(s: JsonObject, onOpen: () -> Unit) {
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth().clickable(onClick = onOpen)) {
        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(rnS(s, "invoice_no"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                Spacer(Modifier.width(8.dp))
                Text(careDate(rnS(s, "invoice_date")), style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                Spacer(Modifier.weight(1f))
                if (s.amount("return_total") > 0) CareBadge("Returned ${rnMoney(s.amount("return_total"))}", Color(0xFFD97706))
            }
            Text(
                rnS(s, "customer_name").ifBlank { "Walk-in" } + (rnS(s, "doctor_name").takeIf { it.isNotBlank() }?.let { " · Dr. $it" } ?: ""),
                style = MaterialTheme.typography.bodySmall,
                color = PharmaSlate,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Row {
                CareInfo("Amount", rnMoney(s.amount("net_amount")), Modifier.weight(1f))
                CareInfo("Paid", rnMoney(s.amount("paid")), Modifier.weight(1f))
                CareInfo("Balance", rnMoney(s.amount("balance")), Modifier.weight(1f), valueColor = if (s.amount("balance") > 0) Color(0xFFDC2626) else PharmaSlate)
                Icon(Icons.Default.ChevronRight, null, Modifier.size(20.dp).align(Alignment.CenterVertically), tint = PharmaMuted)
            }
        }
    }
}

@Composable
private fun SrHistoryCard(h: JsonObject, onView: () -> Unit, onPrint: () -> Unit) {
    Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth().clickable(onClick = onView)) {
        Row(Modifier.padding(start = 10.dp, top = 8.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(rnS(h, "return_no"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                    Spacer(Modifier.width(8.dp))
                    Text(careDate(rnS(h, "created_at")), style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                }
                Text(
                    rnS(h, "customer_name").ifBlank { "—" } + (rnS(h, "contact_no").takeIf { it.isNotBlank() }?.let { " · $it" } ?: ""),
                    style = MaterialTheme.typography.bodySmall,
                    color = PharmaSlate,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text("Bill ${rnS(h, "sale_invoice_no")} · ${careDate(rnS(h, "sale_invoice_date"))}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
            Text(careMoney(h.amount("return_total")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaSlate)
            IconButton(onClick = onPrint, Modifier.size(36.dp)) { Icon(Icons.Default.Print, "Print", Modifier.size(18.dp), tint = PharmaSlate) }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SrHistorySheet(d: JsonObject, onPrint: () -> Unit, onDismiss: () -> Unit) {
    val lines = d.arr("products").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
    val roundOff = rnR2(d.amount("return_total") - lines.sumOf { it.amount("return_amt") })
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Color.White) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 14.dp).padding(bottom = 18.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("Return ${rnS(d, "return_no")}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = PharmaSlate)
            Row {
                CareInfo("Customer", rnS(d, "customer_name"), Modifier.weight(1.3f))
                CareInfo("Original Bill", rnS(d, "sale_invoice_no"), Modifier.weight(1f))
            }
            Row {
                CareInfo("Return date", careDate(rnS(d, "created_at")), Modifier.weight(1.3f))
                CareInfo("Total", careMoney(d.amount("return_total")), Modifier.weight(1f), valueColor = PharmaTealDark)
            }
            Text("Products (${lines.size})", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            lines.forEach { p ->
                Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(8.dp)) {
                        Text(rnS(p, "p_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Row {
                            CareInfo("Batch", rnS(p, "batch_no"), Modifier.weight(1f))
                            CareInfo("Sale Qty", rnQty(p.amount("sale_qty")), Modifier.weight(0.8f))
                            CareInfo("Return Qty", rnQty(p.amount("return_qty")), Modifier.weight(0.8f))
                            CareInfo("Amt", rnMoney(p.amount("return_amt")), Modifier.weight(0.9f))
                        }
                    }
                }
            }
            if (roundOff != 0.0) {
                Row { Text("Round off", Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = PharmaMuted); Text((if (roundOff > 0) "+" else "") + rnMoney(roundOff), style = MaterialTheme.typography.labelMedium, color = PharmaMuted) }
            }
            PharmaOutlinedButton("Print", Modifier.fillMaxWidth(), onClick = onPrint)
        }
    }
}

@Composable
private fun SalesReturnForm(bill: JsonObject, canEdit: Boolean, repo: ReturnsRepository, onCancel: () -> Unit, onSaved: (Int, String) -> Unit) {
    val scope = rememberCoroutineScope()
    val products = remember(bill) { bill.arr("products").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject } }
    val qtys = remember(bill) { mutableStateMapOf<Int, String>() }
    var error by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }

    fun qtyOf(p: JsonObject): Double = qtys[p.jInt("id")]?.toDoubleOrNull() ?: 0.0
    val linesTotal = rnR2(products.sumOf { srLineAmt(it, qtyOf(it)) })
    val remaining = rnR2(maxOf(0.0, bill.amount("net_amount") - bill.amount("return_total")))
    val returnTotal = minOf(Math.round(linesTotal).toDouble(), remaining)
    val roundOff = rnR2(returnTotal - linesTotal)

    fun save() {
        error = null
        val arr = JsonArray()
        products.filter { qtyOf(it) > 0 }.forEach { p ->
            val q = qtyOf(p)
            val amt = srLineAmt(p, q)
            arr.add(JsonObject().apply {
                addProperty("id", p.jInt("id"))
                addProperty("this_return_qty", q)
                addProperty("this_return_amt", amt)
                addProperty("return_qty", p.amount("returned_qty") + q)
                addProperty("return_amt", p.amount("returned_amt") + amt)
            })
        }
        if (arr.size() == 0) { error = "Enter return quantity for at least one product."; return }
        val body = JsonObject().apply {
            addProperty("id", bill.jInt("id"))
            addProperty("return_total", returnTotal)
            addProperty("balance", bill.amount("balance"))
            add("products", arr)
        }
        saving = true
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.saveSalesReturn(body) } }
                    .onSuccess { r -> onSaved(r.jInt("id"), rnS(r, "return_no")) }
                    .onFailure { error = careErr(it, "Save failed.") }
            } finally {
                saving = false
            }
        }
    }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        RnFormBar(if (canEdit) "Create Sales Return" else "Bill Detail", "Bill ${rnS(bill, "invoice_no")} · ${careDate(rnS(bill, "invoice_date"))}", onCancel)
        error?.let { ErrorBanner(it) { error = null } }
        Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
            Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row {
                    CareInfo("Customer", rnS(bill, "customer_name").ifBlank { "Walk-in" }, Modifier.weight(1.3f))
                    CareInfo("Doctor", rnS(bill, "doctor_name"), Modifier.weight(1f))
                }
                Row {
                    CareInfo("Bill Amt", rnMoney(bill.amount("net_amount")), Modifier.weight(1f))
                    CareInfo("Already Returned", rnMoney(bill.amount("return_total")), Modifier.weight(1f))
                    CareInfo("Return Total", careMoney(returnTotal), Modifier.weight(1f), valueColor = PharmaTealDark)
                }
            }
        }
        LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            items(products, key = { it.jInt("id") }) { p ->
                val max = p.jInt("max_return_qty")
                val unit = srQtyUnit(p)
                val q = qtyOf(p)
                Surface(shape = RoundedCornerShape(8.dp), color = if (max <= 0) Color(0xFFF8FAFC) else Color.White, border = BorderStroke(1.dp, if (q > 0) PharmaTeal else PharmaBorder), modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(rnS(p, "p_name"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                Text(
                                    listOfNotNull(rnS(p, "pack_size").takeIf { it.isNotBlank() }, "Batch ${rnS(p, "batch_no").ifBlank { "—" }}", "Exp ${rnExp(rnS(p, "exp_date"))}").joinToString(" · "),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaMuted,
                                )
                            }
                            if (max <= 0) CareBadge("Fully returned", Color(0xFF64748B))
                        }
                        Row {
                            CareInfo("Sold", "${rnQty(p.amount("qty"))} $unit", Modifier.weight(1f))
                            CareInfo("Returned", "${rnQty(p.amount("returned_qty"))} $unit", Modifier.weight(1f))
                            CareInfo("MRP", rnMoney(p.amount("mrp")), Modifier.weight(0.9f))
                            CareInfo("Rate", rnMoney(srUnitRate(p)), Modifier.weight(0.9f))
                        }
                        Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            if (canEdit && max > 0) {
                                SalesFormField(
                                    "Return qty ($unit, max $max)",
                                    qtys[p.jInt("id")] ?: "",
                                    { v ->
                                        val t = v.filter { it.isDigit() }.take(6)
                                        if ((t.toIntOrNull() ?: 0) > max) {
                                            error = "Return qty cannot exceed $max $unit for ${rnS(p, "p_name")}."
                                            qtys[p.jInt("id")] = ""
                                        } else qtys[p.jInt("id")] = t
                                    },
                                    Modifier.weight(1f),
                                    keyboardType = KeyboardType.Number,
                                )
                            } else Spacer(Modifier.weight(1f))
                            Column(horizontalAlignment = Alignment.End) {
                                Text("Amount", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                Text(rnMoney(srLineAmt(p, q)), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Bold, color = PharmaTealDark)
                            }
                        }
                    }
                }
            }
        }
        if (linesTotal > 0) {
            Surface(shape = RoundedCornerShape(8.dp), color = PharmaTeal.copy(alpha = 0.06f), modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(8.dp)) {
                    if (roundOff != 0.0) {
                        Row { Text("Round off", Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = PharmaMuted); Text((if (roundOff > 0) "+" else "") + rnMoney(roundOff), style = MaterialTheme.typography.labelMedium, color = PharmaMuted) }
                    }
                    Row { Text("Return Total", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate); Text(careMoney(returnTotal), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, color = PharmaTealDark) }
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PharmaOutlinedButton("Close", Modifier.weight(1f), onClick = onCancel)
            if (canEdit) PharmaPrimaryButton(if (saving) "Saving…" else "Submit Return", Modifier.weight(1.5f), enabled = !saving && returnTotal > 0) { save() }
        }
    }
}

// ───────────────────────── Return deduction % ─────────────────────────

@Composable
private fun DeductionTab(repo: ReturnsRepository, canEdit: Boolean) {
    val scope = rememberCoroutineScope()
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var months by remember { mutableStateOf("") }
    var pct by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }

    fun load() {
        scope.launch {
            loading = true
            runCatching { withContext(Dispatchers.IO) { repo.deductions() } }
                .onSuccess { rows = it }
                .onFailure { error = careErr(it, "Failed to load.") }
            loading = false
        }
    }
    LaunchedEffect(Unit) { load() }

    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }
        if (canEdit) {
            Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder)) {
                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Add deduction slot", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
                        SalesFormField("Months before expiry *", months, { months = it.filter { c -> c.isDigit() }.take(3) }, Modifier.weight(1f), keyboardType = KeyboardType.Number)
                        SalesFormField(
                            "Deduction % *",
                            pct,
                            { v -> if (v.isEmpty() || Regex("""^\d{0,3}(\.\d{0,2})?$""").matches(v)) pct = v },
                            Modifier.weight(1f),
                            keyboardType = KeyboardType.Decimal,
                        )
                    }
                    PharmaPrimaryButton(if (saving) "Saving…" else "Submit", Modifier.fillMaxWidth(), enabled = !saving) {
                        val m = months.toIntOrNull()
                        val p = pct.toDoubleOrNull()
                        when {
                            m == null -> error = "Enter return months before expiry."
                            p == null || p < 0 || p > 100 -> error = "Deduction % must be between 0 and 100."
                            else -> {
                                error = null
                                saving = true
                                scope.launch {
                                    try {
                                        runCatching { withContext(Dispatchers.IO) { repo.addDeduction(m, p) } }
                                            .onSuccess { toast = "Added successfully."; months = ""; pct = ""; load() }
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
        Surface(shape = RoundedCornerShape(10.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.weight(1f, fill = false)) {
            Column {
                Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 8.dp)) {
                    Text("#", Modifier.width(32.dp), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaMuted)
                    Text("Return Months", Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaMuted)
                    Text("Deduction %", Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaMuted, textAlign = TextAlign.End)
                }
                HorizontalDivider(color = PharmaBorder)
                when {
                    loading -> Box(Modifier.fillMaxWidth().height(100.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp) }
                    rows.isEmpty() -> CareEmpty(Icons.Default.Percent, "No deduction slots yet.")
                    else -> LazyColumn {
                        items(rows.withIndex().toList()) { (i, r) ->
                            Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 8.dp)) {
                                Text("${i + 1}", Modifier.width(32.dp), style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                                Text("${r.jInt("return_months")} months", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = PharmaSlate)
                                Text("${rnMoney(r.amount("deduction_pct"))}%", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaTealDark, textAlign = TextAlign.End)
                            }
                            HorizontalDivider(color = PharmaBorder)
                        }
                    }
                }
            }
        }
        if (!canEdit) Text("View only — you need Return Note edit rights to add slots.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
    }
}
