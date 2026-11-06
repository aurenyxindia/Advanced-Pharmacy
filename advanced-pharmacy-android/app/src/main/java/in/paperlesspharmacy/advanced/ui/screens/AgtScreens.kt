package `in`.paperlesspharmacy.advanced.ui.screens

import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.DirectionsBike
import androidx.compose.material.icons.filled.LocalShipping
import androidx.compose.material.icons.filled.OpenInNew
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.RestartAlt
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.BuildConfig
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.transaction.AgtRepository
import `in`.paperlesspharmacy.advanced.ui.AgtDoSale
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

// ───────────────────────── Shared helpers ─────────────────────────

internal val AgtStatuses = listOf("Placed", "Ready", "Out for Delivery", "Delivered", "Cancelled", "All")
private val AgtPopupRoles = setOf("ho_admin", "branch_admin", "pharmacist", "cashier")

internal fun agtObjects(o: JsonObject, key: String): List<JsonObject> {
    val a = o.arr(key)
    return (0 until a.size()).mapNotNull { a.get(it).takeIf { e -> e.isJsonObject }?.asJsonObject }
}

internal fun agtItemName(it: JsonObject): String = it.str("product_name").ifBlank { it.str("p_name") }

internal fun agtOpenForSale(o: JsonObject): Boolean = o.str("status") == "Placed" && o.jInt("sale_id") <= 0

internal fun agtIsPdf(url: String): Boolean = url.lowercase().contains(".pdf")

internal fun agtAbsoluteUrl(url: String): String {
    if (url.isBlank() || url.startsWith("http", ignoreCase = true)) return url
    val root = BuildConfig.API_BASE.trimEnd('/').removeSuffix("/api")
    return "$root/${url.trimStart('/')}"
}

internal fun agtHandoff(o: JsonObject): AgtDoSale = AgtDoSale(
    orderId = o.jInt("id"),
    orderNo = o.str("order_no"),
    patientNo = o.str("patient_no"),
    patientName = o.str("patient_name"),
    patientContact = o.str("patient_contact"),
    patientAddress = o.str("patient_address"),
)

internal fun agtDateTime(raw: String): String {
    if (raw.isBlank()) return "—"
    val time = if (raw.length >= 16 && raw[10] in " T") " " + raw.substring(11, 16) else ""
    return formatSaleDate(raw) + time
}

internal fun agtDecodeBitmap(bytes: ByteArray, maxSide: Int = 2048): Bitmap? {
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
    if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null
    var sample = 1
    while (bounds.outWidth / sample > maxSide || bounds.outHeight / sample > maxSide) sample *= 2
    return BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
}

private fun agtErr(t: Throwable, fallback: String): String = t.message?.takeIf { it.isNotBlank() } ?: fallback

internal fun agtOpenExternal(context: android.content.Context, url: String): String? =
    runCatching {
        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(agtAbsoluteUrl(url))).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        null
    }.getOrElse { "No app found to open this file." }

private fun agtDial(context: android.content.Context, phone: String) {
    val digits = phone.filter { it.isDigit() || it == '+' }
    if (digits.isBlank()) return
    runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$digits")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

private fun agtShareText(context: android.content.Context, text: String, title: String) {
    runCatching {
        val send = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_TEXT, text)
        }
        context.startActivity(Intent.createChooser(send, title).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}

private data class AgtStatusStyle(val bg: Color, val fg: Color)

private fun agtStatusStyle(status: String): AgtStatusStyle = when {
    status == "Placed" -> AgtStatusStyle(Color(0xFFFEF3C7), Color(0xFF92400E))
    status == "Ready" -> AgtStatusStyle(Color(0xFFE0F2FE), Color(0xFF075985))
    status.lowercase().contains("out") -> AgtStatusStyle(Color(0xFFDBEAFE), Color(0xFF1D4ED8))
    status == "Delivered" -> AgtStatusStyle(Color(0xFFDCFCE7), Color(0xFF166534))
    status == "Cancelled" -> AgtStatusStyle(Color(0xFFF1F5F9), Color(0xFF475569))
    else -> AgtStatusStyle(Color(0xFFF8FAFC), PharmaSlate)
}

@Composable
internal fun AgtStatusBadge(status: String) {
    val s = agtStatusStyle(status)
    Text(
        status.ifBlank { "—" },
        Modifier.clip(RoundedCornerShape(4.dp)).background(s.bg).padding(horizontal = 6.dp, vertical = 1.dp),
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.SemiBold,
        color = s.fg,
        maxLines = 1,
    )
}

/** Prescription thumbnail (image) or "Open PDF" button. Tap → zoom viewer. */
@Composable
internal fun AgtPrescriptionThumb(url: String, repo: AgtRepository, onOpen: () -> Unit, modifier: Modifier = Modifier) {
    if (url.isBlank()) return
    if (agtIsPdf(url)) {
        OutlinedButton(onClick = onOpen, modifier = modifier.heightIn(min = 32.dp), contentPadding = PaddingValues(horizontal = 10.dp)) {
            Icon(Icons.Default.Description, null, Modifier.size(16.dp), tint = PharmaTeal)
            Spacer(Modifier.width(4.dp))
            Text("Open PDF prescription", style = MaterialTheme.typography.labelMedium)
        }
        return
    }
    var bmp by remember(url) { mutableStateOf<Bitmap?>(null) }
    var failed by remember(url) { mutableStateOf(false) }
    LaunchedEffect(url) {
        runCatching { withContext(Dispatchers.IO) { agtDecodeBitmap(repo.prescriptionBytes(url), 900) } }
            .onSuccess { bmp = it; failed = it == null }
            .onFailure { failed = true }
    }
    Box(
        modifier
            .size(width = 140.dp, height = 110.dp)
            .clip(RoundedCornerShape(8.dp))
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .background(Color(0xFFF8FAFC))
            .clickable(onClick = onOpen),
        contentAlignment = Alignment.Center,
    ) {
        val b = bmp
        when {
            b != null -> Image(b.asImageBitmap(), "Prescription", Modifier.fillMaxSize(), contentScale = ContentScale.Crop)
            failed -> Text("Tap to open", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            else -> CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
        }
    }
}

/** Full-screen prescription viewer: pinch / buttons zoom 0.5–4×, pan, copy link, open full. */
@Composable
internal fun AgtRxViewer(url: String, title: String, repo: AgtRepository, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val clipboard = LocalClipboardManager.current
    var bmp by remember(url) { mutableStateOf<Bitmap?>(null) }
    var loading by remember(url) { mutableStateOf(!agtIsPdf(url)) }
    var note by remember { mutableStateOf<String?>(null) }
    var scale by remember { mutableFloatStateOf(1f) }
    var offset by remember { mutableStateOf(Offset.Zero) }
    LaunchedEffect(url) {
        if (agtIsPdf(url)) return@LaunchedEffect
        runCatching { withContext(Dispatchers.IO) { agtDecodeBitmap(repo.prescriptionBytes(url)) } }
            .onSuccess { bmp = it; if (it == null) note = "Could not show this image." }
            .onFailure { note = agtErr(it, "Could not load the prescription.") }
        loading = false
    }
    LaunchedEffect(note) {
        if (note != null) {
            delay(3000)
            note = null
        }
    }
    fun zoom(to: Float) {
        scale = to.coerceIn(0.5f, 4f)
        if (scale <= 1f) offset = Offset.Zero
    }
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize(), color = Color(0xFF0F172A)) {
            Column(Modifier.fillMaxSize()) {
                Row(
                    Modifier.fillMaxWidth().background(Color(0xFF1E293B)).statusBarsPadding().padding(horizontal = 6.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        title,
                        Modifier.weight(1f).padding(start = 4.dp),
                        style = MaterialTheme.typography.labelLarge,
                        color = Color.White,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    if (!agtIsPdf(url)) {
                        IconButton(onClick = { zoom(scale - 0.25f) }, Modifier.size(34.dp)) { Icon(Icons.Default.Remove, "Zoom out", tint = Color.White) }
                        Text("${(scale * 100).toInt()}%", style = MaterialTheme.typography.labelSmall, color = Color.White)
                        IconButton(onClick = { zoom(scale + 0.25f) }, Modifier.size(34.dp)) { Icon(Icons.Default.Add, "Zoom in", tint = Color.White) }
                        IconButton(onClick = { zoom(1f); offset = Offset.Zero }, Modifier.size(34.dp)) { Icon(Icons.Default.RestartAlt, "Reset", tint = Color.White) }
                    }
                    IconButton(onClick = {
                        clipboard.setText(AnnotatedString(agtAbsoluteUrl(url)))
                        note = "Link copied."
                    }, Modifier.size(34.dp)) { Icon(Icons.Default.ContentCopy, "Copy link", tint = Color.White) }
                    IconButton(onClick = { agtOpenExternal(context, url)?.let { note = it } }, Modifier.size(34.dp)) {
                        Icon(Icons.Default.OpenInNew, "Open full", tint = Color.White)
                    }
                    IconButton(onClick = onDismiss, Modifier.size(34.dp)) { Icon(Icons.Default.Close, "Close", tint = Color.White) }
                }
                Box(
                    Modifier
                        .fillMaxWidth()
                        .weight(1f)
                        .pointerInput(Unit) {
                            detectTransformGestures { _, pan, gestureZoom, _ ->
                                scale = (scale * gestureZoom).coerceIn(0.5f, 4f)
                                offset = if (scale > 1f) offset + pan else Offset.Zero
                            }
                        },
                    contentAlignment = Alignment.Center,
                ) {
                    val b = bmp
                    when {
                        agtIsPdf(url) -> Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Icon(Icons.Default.Description, null, Modifier.size(48.dp), tint = Color.White)
                            Text("PDF prescription", color = Color.White, style = MaterialTheme.typography.bodyMedium)
                            PharmaPrimaryButton("Open PDF") { agtOpenExternal(context, url)?.let { note = it } }
                        }
                        loading -> CircularProgressIndicator(color = Color.White)
                        b != null -> Image(
                            b.asImageBitmap(),
                            "Prescription",
                            Modifier
                                .fillMaxSize()
                                .graphicsLayer(scaleX = scale, scaleY = scale, translationX = offset.x, translationY = offset.y),
                            contentScale = ContentScale.Fit,
                        )
                        else -> Text("Prescription could not be shown. Use Open full.", color = Color.White, style = MaterialTheme.typography.bodySmall)
                    }
                    note?.let {
                        Surface(
                            color = Color(0xE6FFFFFF),
                            shape = RoundedCornerShape(8.dp),
                            modifier = Modifier.align(Alignment.BottomCenter).padding(16.dp),
                        ) { Text(it, Modifier.padding(horizontal = 10.dp, vertical = 6.dp), style = MaterialTheme.typography.labelMedium) }
                    }
                }
            }
        }
    }
}

/** Items table used in order detail, popup and sales "Add to bill" sheet. */
@Composable
internal fun AgtItemsTable(items: List<JsonObject>, showStock: Boolean, showMoney: Boolean = true) {
    val scroll = rememberScrollState()
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp)),
    ) {
        Row(
            Modifier.fillMaxWidth().horizontalScroll(scroll).background(BillGridHeaderBg),
            horizontalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            BillGridTh("PRODUCT", 150.dp)
            BillGridTh("BATCH", 76.dp)
            BillGridTh("QTY", 40.dp, alignEnd = true)
            if (showMoney) {
                BillGridTh("MRP", 64.dp, alignEnd = true)
                BillGridTh("TOTAL", 72.dp, alignEnd = true)
            }
            if (showStock) BillGridTh("STOCK", 92.dp)
        }
        if (items.isEmpty()) {
            Text("No items.", Modifier.padding(10.dp), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        }
        items.forEachIndexed { i, it ->
            Row(
                Modifier.fillMaxWidth().horizontalScroll(scroll).heightIn(min = 32.dp),
                horizontalArrangement = Arrangement.spacedBy(4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                BillGridCellText(agtItemName(it), 150.dp, fontWeight = FontWeight.SemiBold)
                BillGridCellText(it.str("batch_no").ifBlank { "—" }, 76.dp)
                BillGridCellText(pNumText(it.amount("qty"), true).ifBlank { "0" }, 40.dp, alignEnd = true)
                if (showMoney) {
                    BillGridCellText(formatAmount2(it.amount("unit_mrp")), 64.dp, alignEnd = true)
                    BillGridCellText(formatAmount2(it.amount("line_total")), 72.dp, alignEnd = true, fontWeight = FontWeight.SemiBold)
                }
                if (showStock) {
                    val segs = agtObjects(it, "fulfillment_segments")
                    val short = it.amount("qty_unfulfilled")
                    BillGridCellText(
                        when {
                            segs.isEmpty() -> "Out of stock"
                            short > 0 -> "OK · short ${pNumText(short, true)}"
                            else -> "OK"
                        },
                        92.dp,
                        color = when {
                            segs.isEmpty() -> MaterialTheme.colorScheme.error
                            short > 0 -> Color(0xFF92400E)
                            else -> Color(0xFF15803D)
                        },
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }
            if (i < items.lastIndex) HorizontalDivider(color = PharmaBorder.copy(alpha = 0.45f))
        }
    }
}

// ───────────────────────── Module screen ─────────────────────────

@Composable
fun AgtModuleScreen(vm: AppViewModel, onBack: () -> Unit, onDoSale: () -> Unit) {
    val repo = remember { vm.agt() }
    val scope = rememberCoroutineScope()
    val canEdit = vm.canEditAgt()
    val wide = LocalConfiguration.current.screenWidthDp >= 600

    var tab by rememberSaveable { mutableIntStateOf(0) }
    var status by rememberSaveable { mutableStateOf("Placed") }
    var search by rememberSaveable { mutableStateOf("") }
    var counts by remember { mutableStateOf<Map<String, Int>>(emptyMap()) }
    var orders by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var allowCancel by remember { mutableStateOf<Boolean?>(null) }
    var savingPolicy by remember { mutableStateOf(false) }
    var detail by remember { mutableStateOf<JsonObject?>(null) }
    var detailLoadingId by remember { mutableIntStateOf(0) }
    var riders by remember { mutableStateOf<List<JsonObject>?>(null) }
    var busy by remember { mutableStateOf(false) }
    var confirm by remember { mutableStateOf<Triple<JsonObject, String, Boolean>?>(null) }
    var rx by remember { mutableStateOf<Pair<String, String>?>(null) }

    fun loadCounts() {
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.orderCounts() } }.onSuccess { counts = it }
        }
    }

    fun loadOrders(silent: Boolean = false) {
        scope.launch {
            if (!silent) loading = true
            runCatching { withContext(Dispatchers.IO) { repo.listOrders(status, 60) } }
                .onSuccess { orders = it }
                .onFailure { if (!silent) error = agtErr(it, "Could not load orders.") }
            loading = false
        }
    }

    fun openOrder(id: Int) {
        if (id <= 0) return
        detailLoadingId = id
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.getOrder(id) } }
                .onSuccess { detail = it }
                .onFailure { error = agtErr(it, "Could not load order.") }
            detailLoadingId = 0
            if (canEdit && riders == null) {
                runCatching { withContext(Dispatchers.IO) { repo.riders() } }.onSuccess { riders = it }
            }
        }
    }

    fun doSale(o: JsonObject) {
        if (!agtOpenForSale(o)) {
            error = if (o.jInt("sale_id") > 0 || o.str("sale_invoice_no").isNotBlank()) {
                "This order is already billed (${o.str("sale_invoice_no").ifBlank { "sale #" + o.jInt("sale_id") }}). Use Ready tab for delivery."
            } else {
                "Only new (Placed) orders can be billed."
            }
            return
        }
        vm.agtDoSale.value = agtHandoff(o)
        detail = null
        onDoSale()
    }

    fun runStatus(o: JsonObject, newStatus: String, sendWa: Boolean) {
        busy = true
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.updateStatus(o.jInt("id"), newStatus, sendWa) } }
                    .onSuccess { res ->
                        var msg = res.str("message").ifBlank { "Status updated to $newStatus." }
                        val wa = res.obj("data").obj("whatsapp")
                        if (wa.has("ok")) {
                            msg += if (wa.bool("ok")) " WhatsApp sent to patient." else " WhatsApp was not sent: ${wa.str("error").ifBlank { "unknown error" }}"
                        }
                        toast = msg
                        detail = null
                        loadCounts()
                        loadOrders(silent = true)
                    }
                    .onFailure { error = agtErr(it, "Could not update status.") }
            } finally {
                busy = false
            }
        }
    }

    fun saveRider(o: JsonObject, riderId: Int) {
        busy = true
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.assignRider(o.jInt("id"), riderId) } }
                    .onSuccess { res ->
                        toast = res.str("message").ifBlank { if (riderId > 0) "Rider assigned." else "Rider removed." }
                        runCatching { withContext(Dispatchers.IO) { repo.getOrder(o.jInt("id")) } }.onSuccess { detail = it }
                        loadOrders(silent = true)
                    }
                    .onFailure { error = agtErr(it, "Could not assign rider.") }
            } finally {
                busy = false
            }
        }
    }

    fun savePolicy(allow: Boolean) {
        savingPolicy = true
        scope.launch {
            try {
                runCatching { withContext(Dispatchers.IO) { repo.saveAllowCustomerCancel(allow) } }
                    .onSuccess { res ->
                        val d = res.obj("data")
                        allowCancel = if (d.has("allow_customer_cancel")) d.bool("allow_customer_cancel") else allow
                        toast = res.str("message").ifBlank { "Cancellation policy saved." }
                    }
                    .onFailure { error = agtErr(it, "Could not save setting.") }
            } finally {
                savingPolicy = false
            }
        }
    }

    LaunchedEffect(Unit) {
        loadCounts()
        runCatching { withContext(Dispatchers.IO) { repo.allowCustomerCancel() } }.onSuccess { allowCancel = it }
    }
    LaunchedEffect(status) { loadOrders() }
    LaunchedEffect(Unit) {
        while (true) {
            delay(45_000)
            loadCounts()
            if (tab == 0) loadOrders(silent = true)
        }
    }
    val openRequest by vm.agtOpenOrder.collectAsState()
    LaunchedEffect(openRequest) {
        if (openRequest > 0) {
            tab = 0
            openOrder(openRequest)
            vm.agtOpenOrder.value = 0
        }
    }
    LaunchedEffect(toast) {
        if (toast != null) {
            delay(5000)
            toast = null
        }
    }

    val shown = remember(orders, search) {
        val q = search.trim().lowercase()
        if (q.isEmpty()) orders else orders.filter { o ->
            listOf("order_no", "patient_name", "patient_contact", "sale_invoice_no", "rider_name").any { o.str(it).lowercase().contains(q) }
        }
    }

    Column(
        Modifier.fillMaxSize().padding(CompactScreenPadding),
        verticalArrangement = Arrangement.spacedBy(5.dp),
    ) {
        PoHeader(
            "AuraGharTak",
            "Share live stock with patients, fulfil home-delivery orders, WhatsApp bill on dispatch.",
            onBack,
        ) {
            IconButton(onClick = { loadCounts(); loadOrders() }, modifier = Modifier.size(34.dp)) {
                Icon(Icons.Default.Refresh, "Refresh", Modifier.size(20.dp), tint = PharmaSlate)
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            val k = listOf(
                "New orders" to (counts["Placed"] ?: 0),
                "Ready" to (counts["Ready"] ?: 0),
                "Out for delivery" to (counts["Out for Delivery"] ?: 0),
                "Delivered" to (counts["Delivered"] ?: 0),
            )
            k.forEach { (label, v) -> SalesLogKpiChip(label, v.toString(), Modifier.weight(1f)) }
        }
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            val placed = counts["Placed"] ?: 0
            PoChip("Orders" + if (placed > 0) " ($placed new)" else "", tab == 0) { tab = 0 }
            PoChip("Share stock catalogue", tab == 1) { tab = 1 }
        }
        toast?.let { PoToast(it) { toast = null } }
        error?.let { ErrorBanner(it) { error = null } }

        if (tab == 1) {
            Box(Modifier.weight(1f).fillMaxWidth()) {
                AgtShareTab(repo, canEdit, onToast = { toast = it }, onError = { error = it })
            }
            return@Column
        }

        AgtPolicyCard(allowCancel, canEdit, savingPolicy) { savePolicy(it) }
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            AgtStatuses.forEach { s ->
                val n = counts[s]
                PoChip(if (n != null && s != "All") "$s ($n)" else s, status == s) { status = s }
            }
        }
        SalesDenseSearchField(
            value = search,
            onValueChange = { search = it },
            placeholder = "Filter by order no, patient, mobile, bill…",
            modifier = Modifier.fillMaxWidth(),
            leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
            trailingIcon = {
                if (search.isNotEmpty()) {
                    IconButton(onClick = { search = "" }, modifier = Modifier.size(22.dp)) {
                        Icon(Icons.Default.Close, "Clear", Modifier.size(14.dp), tint = PharmaMuted)
                    }
                }
            },
        )
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                loading && orders.isEmpty() -> LoadingBlock("Loading orders…")
                shown.isEmpty() -> PharmaCard {
                    Text(
                        if (orders.isEmpty()) "No orders in this status." else "No orders match the filter.",
                        Modifier.fillMaxWidth().padding(20.dp),
                        style = MaterialTheme.typography.bodySmall,
                        color = PharmaMuted,
                        textAlign = TextAlign.Center,
                    )
                }
                else -> LazyVerticalGrid(
                    columns = GridCells.Adaptive(minSize = if (wide) 320.dp else 280.dp),
                    modifier = Modifier.fillMaxSize(),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    items(shown, key = { it.jInt("id") }) { o ->
                        AgtOrderCard(
                            o = o,
                            canEdit = canEdit,
                            loading = detailLoadingId == o.jInt("id"),
                            busy = busy,
                            onOpen = { openOrder(o.jInt("id")) },
                            onDoSale = { doSale(o) },
                            onStatus = { st, wa -> confirm = Triple(o, st, wa) },
                        )
                    }
                    item(span = { GridItemSpan(maxLineSpan) }) { Spacer(Modifier.height(8.dp)) }
                }
            }
            if (loading && orders.isNotEmpty()) {
                LinearProgressIndicator(Modifier.fillMaxWidth().align(Alignment.TopCenter), color = PharmaTeal)
            }
        }
        if (!canEdit) {
            Text(
                "View only — enable Edit on AuraGharTak in User Rights to share links or update orders.",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
        }
    }

    detail?.let { o ->
        AgtOrderSheet(
            o = o,
            repo = repo,
            canEdit = canEdit,
            riders = riders,
            busy = busy,
            onDismiss = { detail = null },
            onDoSale = { doSale(o) },
            onStatus = { st, wa -> confirm = Triple(o, st, wa) },
            onSaveRider = { saveRider(o, it) },
            onRx = { rx = o.str("prescription_url") to "Prescription · ${o.str("order_no")}" },
        )
    }
    confirm?.let { (o, st, wa) ->
        val text = when {
            st == "Out for Delivery" && wa -> "Send WhatsApp with bill PDF to patient and mark Out for Delivery?"
            st == "Out for Delivery" -> "Mark Out for Delivery without sending WhatsApp?"
            st == "Delivered" -> "Mark as Delivered and notify patient?"
            st == "Cancelled" -> "Cancel this order?"
            else -> "Update status to $st?"
        }
        AlertDialog(
            onDismissRequest = { confirm = null },
            title = { Text("Order ${o.str("order_no")}", style = MaterialTheme.typography.titleSmall) },
            text = { Text(text, style = MaterialTheme.typography.bodySmall) },
            confirmButton = {
                TextButton(onClick = {
                    confirm = null
                    runStatus(o, st, wa)
                }) {
                    Text(
                        if (st == "Cancelled") "Cancel order" else "Yes",
                        color = if (st == "Cancelled") MaterialTheme.colorScheme.error else PharmaTeal,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            },
            dismissButton = { TextButton(onClick = { confirm = null }) { Text(if (st == "Cancelled") "Keep order" else "No") } },
        )
    }
    rx?.let { (url, title) -> AgtRxViewer(url, title, repo) { rx = null } }
    if (busy) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Surface(shape = RoundedCornerShape(10.dp), shadowElevation = 6.dp) {
                Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                    Spacer(Modifier.width(8.dp))
                    Text("Updating…", style = MaterialTheme.typography.labelMedium)
                }
            }
        }
    }
}

@Composable
private fun AgtPolicyCard(allow: Boolean?, canEdit: Boolean, saving: Boolean, onSave: (Boolean) -> Unit) {
    Surface(shape = RoundedCornerShape(8.dp), color = Color.White, border = BorderStroke(1.dp, PharmaBorder), modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(horizontal = 8.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Customer order cancellation", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                Text(
                    "Shop-wide: can patients cancel a new order from their AuraGharTak link?",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
            }
            when {
                allow == null -> CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                canEdit -> Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (saving) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                    PSmallChip("No", selected = !allow) { if (allow && !saving) onSave(false) }
                    PSmallChip("Yes", selected = allow) { if (!allow && !saving) onSave(true) }
                }
                else -> Text(
                    if (allow) "Yes — patients can cancel new orders" else "No — call pharmacy only",
                    Modifier
                        .clip(RoundedCornerShape(4.dp))
                        .background(if (allow) Color(0xFFDCFCE7) else Color(0xFFF1F5F9))
                        .padding(horizontal = 6.dp, vertical = 2.dp),
                    style = MaterialTheme.typography.labelSmall,
                    color = if (allow) Color(0xFF166534) else Color(0xFF475569),
                )
            }
        }
    }
}

@Composable
private fun AgtOrderCard(
    o: JsonObject,
    canEdit: Boolean,
    loading: Boolean,
    busy: Boolean,
    onOpen: () -> Unit,
    onDoSale: () -> Unit,
    onStatus: (String, Boolean) -> Unit,
) {
    val st = o.str("status")
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = Color.White,
        border = BorderStroke(1.dp, if (st == "Placed") Color(0xFFFCD34D) else PharmaBorder),
        modifier = Modifier.fillMaxWidth().clickable(onClick = onOpen),
    ) {
        Column(Modifier.padding(horizontal = 10.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(o.str("order_no"), Modifier.weight(1f), style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold, color = PharmaTeal)
                if (loading) CircularProgressIndicator(Modifier.size(14.dp).padding(end = 4.dp), strokeWidth = 2.dp)
                AgtStatusBadge(st)
            }
            Text(
                "${o.str("patient_name").ifBlank { "—" }} · ${o.str("patient_contact")}",
                style = MaterialTheme.typography.bodySmall,
                fontWeight = FontWeight.SemiBold,
                color = PharmaSlate,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            if (o.str("patient_address").isNotBlank()) {
                Text(o.str("patient_address"), style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "Est. ${formatAmount2(o.amount("estimated_total"))} · ${o.jInt("item_count")} item(s)",
                    Modifier.weight(1f),
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = PharmaSlate,
                )
                Text(agtDateTime(o.str("entry_date")), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
            }
            if (o.str("sale_invoice_no").isNotBlank()) {
                Text(
                    "Bill: ${o.str("sale_invoice_no")} · ${formatAmount2(o.amount("sale_net"))}",
                    style = MaterialTheme.typography.labelSmall,
                    color = Color(0xFF15803D),
                    fontWeight = FontWeight.SemiBold,
                )
            }
            if (o.str("rider_name").isNotBlank()) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.DirectionsBike, null, Modifier.size(13.dp), tint = PharmaMuted)
                    Text(
                        " ${o.str("rider_name")}" + if (o.bool("tracking_active")) " · Live" else "",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                    )
                }
            }
            if (canEdit) {
                when {
                    agtOpenForSale(o) -> AgtCardAction("Do sale", Icons.Default.ShoppingCart, !busy, onDoSale)
                    st == "Ready" -> AgtCardAction("Out for delivery", Icons.Default.LocalShipping, !busy) { onStatus("Out for Delivery", true) }
                    st == "Out for Delivery" -> AgtCardAction("Mark delivered", Icons.Default.LocalShipping, !busy) { onStatus("Delivered", true) }
                }
            }
        }
    }
}

@Composable
private fun AgtCardAction(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        enabled = enabled,
        modifier = Modifier.fillMaxWidth().padding(top = 4.dp).heightIn(min = 34.dp),
        contentPadding = PaddingValues(horizontal = 10.dp, vertical = 4.dp),
        shape = RoundedCornerShape(6.dp),
        colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal),
    ) {
        Icon(icon, null, Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AgtOrderSheet(
    o: JsonObject,
    repo: AgtRepository,
    canEdit: Boolean,
    riders: List<JsonObject>?,
    busy: Boolean,
    onDismiss: () -> Unit,
    onDoSale: () -> Unit,
    onStatus: (String, Boolean) -> Unit,
    onSaveRider: (Int) -> Unit,
    onRx: () -> Unit,
) {
    val context = LocalContext.current
    val st = o.str("status")
    val items = agtObjects(o, "items")
    val canAssignRider = canEdit && st != "Delivered" && st != "Cancelled"
    var riderId by remember(o) { mutableIntStateOf(o.jInt("rider_user_id")) }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 12.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(o.str("order_no"), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = PharmaTeal)
                    Text("Placed ${agtDateTime(o.str("entry_date"))}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
                AgtStatusBadge(st)
            }

            AgtBlock("Patient") {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(o.str("patient_name").ifBlank { "—" }, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Text(o.str("patient_contact"), style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                    }
                    if (o.str("patient_contact").isNotBlank()) {
                        IconButton(onClick = { agtDial(context, o.str("patient_contact")) }, Modifier.size(34.dp)) {
                            Icon(Icons.Default.Call, "Call patient", Modifier.size(18.dp), tint = PharmaTeal)
                        }
                    }
                }
                if (o.str("patient_address").isNotBlank()) {
                    Text(o.str("patient_address"), style = MaterialTheme.typography.labelSmall, color = PharmaSlate)
                }
                if (o.str("patient_note").isNotBlank()) {
                    Text("Note: ${o.str("patient_note")}", style = MaterialTheme.typography.labelSmall, color = Color(0xFF92400E))
                }
            }

            if (o.str("prescription_url").isNotBlank()) {
                AgtBlock("Prescription") {
                    AgtPrescriptionThumb(o.str("prescription_url"), repo, onRx)
                }
            }

            AgtBlock("Items (${items.size})") {
                AgtItemsTable(items, showStock = st == "Placed")
                Text(
                    "Estimated ${formatAmount2(o.amount("estimated_total"))}",
                    Modifier.fillMaxWidth(),
                    style = MaterialTheme.typography.labelLarge,
                    fontWeight = FontWeight.Bold,
                    color = PharmaTeal,
                    textAlign = TextAlign.End,
                )
            }

            if (o.str("sale_invoice_no").isNotBlank() || o.jInt("sale_id") > 0) {
                AgtBlock("Sale bill") {
                    Text(
                        "${o.str("sale_invoice_no").ifBlank { "#" + o.jInt("sale_id") }} · Net ${formatAmount2(o.amount("sale_net"))}",
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = Color(0xFF15803D),
                    )
                }
            }

            AgtBlock("Delivery rider") {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        if (o.str("rider_name").isNotBlank()) "${o.str("rider_name")} · ${o.str("rider_phone")}" else "Not assigned.",
                        Modifier.weight(1f),
                        style = MaterialTheme.typography.labelMedium,
                        color = PharmaSlate,
                    )
                    if (o.bool("tracking_active")) {
                        Text(
                            "Live",
                            Modifier.clip(RoundedCornerShape(4.dp)).background(Color(0xFFDCFCE7)).padding(horizontal = 6.dp, vertical = 1.dp),
                            style = MaterialTheme.typography.labelSmall,
                            color = Color(0xFF166534),
                            fontWeight = FontWeight.SemiBold,
                        )
                    }
                    if (o.str("rider_phone").isNotBlank()) {
                        IconButton(onClick = { agtDial(context, o.str("rider_phone")) }, Modifier.size(32.dp)) {
                            Icon(Icons.Default.Call, "Call rider", Modifier.size(16.dp), tint = PharmaTeal)
                        }
                    }
                }
                if (canAssignRider) {
                    when {
                        riders == null -> Text("Loading riders…", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        riders.isEmpty() -> Text(
                            "No delivery staff yet. Add a user with role Delivery staff in My Profile → User Rights.",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                        )
                        else -> {
                            val options = listOf(0) + riders.map { it.jInt("id") }
                            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                POptionDropdown(
                                    "Assign rider",
                                    riderId.toString(),
                                    options.map { it.toString() },
                                    { riderId = it.toIntOrNull() ?: 0 },
                                    Modifier.weight(1f),
                                ) { v ->
                                    val id = v.toIntOrNull() ?: 0
                                    if (id == 0) "— No rider —" else riders.firstOrNull { it.jInt("id") == id }
                                        ?.let { r -> r.str("name") + if (r.str("phone").isNotBlank()) " · ${r.str("phone")}" else "" } ?: "Rider #$id"
                                }
                                PharmaPrimaryButton(
                                    "Save rider",
                                    enabled = !busy && riderId != o.jInt("rider_user_id"),
                                    modifier = Modifier.height(SalesDenseFieldHeight + 4.dp),
                                ) { onSaveRider(riderId) }
                            }
                        }
                    }
                }
            }

            if (canEdit) {
                when (st) {
                    "Placed" -> Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        if (agtOpenForSale(o)) {
                            PharmaPrimaryButton("Do sale", Modifier.weight(1f), enabled = !busy, onClick = onDoSale)
                        }
                        OutlinedButton(
                            onClick = { onStatus("Cancelled", false) },
                            enabled = !busy,
                            modifier = Modifier.weight(1f),
                            colors = ButtonDefaults.outlinedButtonColors(contentColor = MaterialTheme.colorScheme.error),
                            border = BorderStroke(1.dp, MaterialTheme.colorScheme.error),
                        ) { Text("Cancel order", style = MaterialTheme.typography.labelMedium) }
                    }
                    "Ready" -> Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        PharmaPrimaryButton("Out for delivery (WhatsApp bill)", Modifier.fillMaxWidth(), enabled = !busy) { onStatus("Out for Delivery", true) }
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            PharmaOutlinedButton("Out for delivery (no WhatsApp)", Modifier.weight(1f), enabled = !busy) { onStatus("Out for Delivery", false) }
                            PharmaOutlinedButton("Mark delivered", Modifier.weight(1f), enabled = !busy) { onStatus("Delivered", true) }
                        }
                    }
                    "Out for Delivery" -> PharmaPrimaryButton("Mark delivered", Modifier.fillMaxWidth(), enabled = !busy) { onStatus("Delivered", true) }
                }
            }
            Spacer(Modifier.height(10.dp))
        }
    }
}

@Composable
private fun AgtBlock(title: String, content: @Composable ColumnScope.() -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(Color.White)
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .padding(8.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(title.uppercase(), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = PharmaMuted)
        content()
    }
}

// ───────────────────────── Share stock catalogue tab ─────────────────────────

@Composable
private fun AgtShareTab(repo: AgtRepository, canEdit: Boolean, onToast: (String) -> Unit, onError: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val clipboard = LocalClipboardManager.current
    var mode by rememberSaveable { mutableStateOf("existing") }
    var q by rememberSaveable { mutableStateOf("") }
    var results by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    var searched by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<JsonObject?>(null) }
    var name by rememberSaveable { mutableStateOf("") }
    var mobile by rememberSaveable { mutableStateOf("") }
    var address by rememberSaveable { mutableStateOf("") }
    var sharing by remember { mutableStateOf(false) }
    var lastUrl by rememberSaveable { mutableStateOf("") }
    var result by remember { mutableStateOf<Triple<String, String, Boolean>?>(null) }
    var resultUrl by remember { mutableStateOf("") }
    var searchTick by remember { mutableIntStateOf(0) }

    LaunchedEffect(q, searchTick) {
        if (mode != "existing") return@LaunchedEffect
        val term = q.trim()
        if (term.length < 2) {
            results = emptyList()
            searched = false
            return@LaunchedEffect
        }
        delay(300)
        searching = true
        runCatching { withContext(Dispatchers.IO) { repo.searchCustomers(term) } }
            .onSuccess { results = it; searched = true }
            .onFailure { onError(agtErr(it, "Customer search failed.")) }
        searching = false
    }

    fun share(sendWa: Boolean) {
        val pNo: String
        val pName: String
        val pContact: String
        val pAddr: String
        if (mode == "existing") {
            val c = selected ?: run { onError("Select a patient first."); return }
            pNo = c.str("c_no").ifBlank { c.jInt("id").takeIf { it > 0 }?.toString().orEmpty() }
            pName = c.str("c_name").trim()
            pContact = c.str("c_cont").filter { it.isDigit() }
            pAddr = c.str("c_add").trim()
        } else {
            pNo = ""
            pName = name.trim()
            pContact = mobile.filter { it.isDigit() }
            pAddr = address.trim()
        }
        if (pName.isBlank()) { onError("Enter patient name"); return }
        if (pContact.length < 10) { onError("Enter valid 10-digit mobile number"); return }
        val contact10 = pContact.takeLast(10)
        sharing = true
        scope.launch {
            runCatching { withContext(Dispatchers.IO) { repo.createShare(pNo, pName, contact10, pAddr, sendWa) } }
                .onSuccess { res ->
                    val d = res.obj("data")
                    val url = d.str("shop_url")
                    lastUrl = url
                    resultUrl = url
                    result = Triple(pName, contact10, sendWa && d.bool("whatsapp_sent"))
                    onToast(res.str("message").ifBlank { "Stock link created." })
                }
                .onFailure { onError(agtErr(it, "Could not create the link.")) }
            sharing = false
        }
    }

    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        AgtBlock("Share live stock with a patient") {
            Text(
                "The patient gets a link to browse your in-stock products and place a home-delivery order.",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                PoChip("Existing patient", mode == "existing") { mode = "existing" }
                PoChip("New customer", mode == "new") { mode = "new"; selected = null }
            }
            if (mode == "existing") {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    SalesDenseSearchField(
                        value = q,
                        onValueChange = { q = it },
                        placeholder = "Type name or mobile (min 2 chars)",
                        modifier = Modifier.weight(1f),
                        leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
                        trailingIcon = {
                            if (searching) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                        },
                    )
                    PharmaOutlinedButton("Search", Modifier.height(SalesDenseFieldHeight + 4.dp), enabled = q.trim().length >= 2) { searchTick++ }
                }
                selected?.let { c ->
                    Surface(shape = RoundedCornerShape(8.dp), color = Color(0xFFF0FDFA), border = BorderStroke(1.dp, PharmaTeal.copy(alpha = 0.4f))) {
                        Row(Modifier.fillMaxWidth().padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            Box(
                                Modifier.size(34.dp).clip(CircleShape).background(PharmaTeal),
                                contentAlignment = Alignment.Center,
                            ) {
                                Text(c.str("c_name").trim().take(1).uppercase().ifBlank { "P" }, color = Color.White, fontWeight = FontWeight.Bold)
                            }
                            Column(Modifier.weight(1f).padding(start = 8.dp)) {
                                Text("${c.str("c_name")} · ${c.str("c_cont")}", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                if (c.str("c_add").isNotBlank()) {
                                    Text(c.str("c_add"), style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                }
                            }
                            IconButton(onClick = { selected = null }, Modifier.size(28.dp)) {
                                Icon(Icons.Default.Close, "Clear", Modifier.size(16.dp), tint = PharmaMuted)
                            }
                        }
                    }
                }
                if (selected == null) {
                    if (searched && results.isEmpty() && !searching) {
                        Text(
                            "No patient found for \"${q.trim()}\". Check Customer Master.",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                        )
                    }
                    results.forEach { c ->
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(6.dp))
                                .clickable { selected = c }
                                .padding(horizontal = 6.dp, vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Default.Person, null, Modifier.size(16.dp), tint = PharmaMuted)
                            Column(Modifier.weight(1f).padding(start = 6.dp)) {
                                Text(c.str("c_name"), style = MaterialTheme.typography.labelLarge, color = PharmaSlate)
                                Text(
                                    listOf(c.str("c_cont"), c.str("c_add")).filter { it.isNotBlank() }.joinToString(" · "),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaMuted,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis,
                                )
                            }
                        }
                        HorizontalDivider(color = PharmaBorder.copy(alpha = 0.45f))
                    }
                }
            } else {
                SalesFormField("Patient name *", name, { name = it.uppercase() }, Modifier.fillMaxWidth(), isError = false)
                SalesFormField(
                    "Mobile *",
                    mobile,
                    { mobile = it.filter { ch -> ch.isDigit() }.take(12) },
                    Modifier.fillMaxWidth(),
                    keyboardType = KeyboardType.Phone,
                )
                SalesFormField("Address", address, { address = it.uppercase() }, Modifier.fillMaxWidth())
            }
        }
        if (canEdit) {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PharmaPrimaryButton(if (sharing) "Sending…" else "Send on WhatsApp", Modifier.weight(1f), enabled = !sharing) { share(true) }
                PharmaOutlinedButton("Link only", Modifier.weight(1f), enabled = !sharing) { share(false) }
            }
        } else {
            Text(
                "View only — enable Edit on AuraGharTak in User Rights to share links or update orders.",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
            )
        }
        if (lastUrl.isNotBlank()) {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                PharmaOutlinedButton("Copy last link", Modifier.weight(1f)) {
                    clipboard.setText(AnnotatedString(lastUrl))
                    onToast("Link copied.")
                }
                PharmaOutlinedButton("Share last link", Modifier.weight(1f)) { agtShareText(context, lastUrl, "Share stock link") }
            }
        }
        Spacer(Modifier.height(10.dp))
    }

    result?.let { (pName, contact, waSent) ->
        AlertDialog(
            onDismissRequest = { result = null },
            title = { Text("Link for $pName", style = MaterialTheme.typography.titleSmall) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (waSent) {
                        Text("Link shared with $pName on WhatsApp ($contact).", style = MaterialTheme.typography.bodySmall, color = Color(0xFF15803D))
                    } else {
                        Text("WhatsApp was not sent. Copy and share manually.", style = MaterialTheme.typography.bodySmall, color = Color(0xFF92400E))
                    }
                    Surface(shape = RoundedCornerShape(6.dp), color = Color(0xFFF8FAFC), border = BorderStroke(1.dp, PharmaBorder)) {
                        Text(resultUrl, Modifier.fillMaxWidth().padding(8.dp), style = MaterialTheme.typography.labelSmall, color = PharmaSlate)
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        OutlinedButton(onClick = {
                            clipboard.setText(AnnotatedString(resultUrl))
                            onToast("Link copied.")
                        }, Modifier.weight(1f)) {
                            Icon(Icons.Default.ContentCopy, null, Modifier.size(14.dp))
                            Spacer(Modifier.width(4.dp))
                            Text("Copy", style = MaterialTheme.typography.labelMedium)
                        }
                        OutlinedButton(onClick = { agtShareText(context, resultUrl, "Share stock link") }, Modifier.weight(1f)) {
                            Icon(Icons.Default.Share, null, Modifier.size(14.dp))
                            Spacer(Modifier.width(4.dp))
                            Text("Share", style = MaterialTheme.typography.labelMedium)
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { result = null }) { Text("Close") } },
        )
    }
}

// ───────────────────────── New order popup (global) ─────────────────────────

/**
 * Polls new (Placed) orders every 5 s and pops each unseen one (web `AgtOrderNotificationService`).
 * First poll only seeds the seen set. Roles: HO/branch admin, pharmacist, cashier with AGT view/edit.
 */
@Composable
fun AgtNewOrderWatcher(vm: AppViewModel, onDoSale: () -> Unit, onOpenOrder: (Int) -> Unit) {
    val user by vm.user.collectAsState()
    val repo = remember { vm.agt() }
    val queue = remember { ArrayDeque<Int>() }
    var active by remember { mutableStateOf<JsonObject?>(null) }
    var activeLoading by remember { mutableStateOf(false) }
    var qr by remember { mutableStateOf<Bitmap?>(null) }
    var qrTried by remember { mutableStateOf(false) }
    var rx by remember { mutableStateOf<String?>(null) }

    suspend fun showNext() {
        while (active == null && queue.isNotEmpty()) {
            val id = queue.removeFirst()
            activeLoading = true
            val o = runCatching { withContext(Dispatchers.IO) { repo.getOrder(id) } }.getOrNull()
            activeLoading = false
            if (o != null && o.str("status") == "Placed") active = o
        }
    }

    LaunchedEffect(user?.id) {
        val u = user ?: return@LaunchedEffect
        if (u.role !in AgtPopupRoles) return@LaunchedEffect
        val seen = HashSet<Int>()
        var seeded = false
        queue.clear()
        active = null
        while (true) {
            if (vm.rights.isReady() && vm.canUseAgt()) {
                runCatching { withContext(Dispatchers.IO) { repo.listOrders("Placed", 50) } }.onSuccess { list ->
                    val ids = list.map { it.jInt("id") }.filter { it > 0 }
                    if (!seeded) {
                        seen.addAll(ids)
                        seeded = true
                    } else {
                        ids.filter { it !in seen }.sorted().forEach {
                            seen.add(it)
                            queue.addLast(it)
                        }
                    }
                }
                showNext()
            }
            delay(5_000)
        }
    }
    LaunchedEffect(active != null) {
        if (active != null && !qrTried) {
            qrTried = true
            runCatching { withContext(Dispatchers.IO) { repo.paymentQr(user?.clientId)?.let { agtDecodeBitmap(it, 600) } } }
                .onSuccess { qr = it }
        }
    }

    val o = active ?: return
    fun dismiss() {
        active = null
    }
    AgtNewOrderDialog(
        o = o,
        repo = repo,
        qr = qr,
        loading = activeLoading,
        onDismiss = { dismiss() },
        onOpen = {
            val id = o.jInt("id")
            dismiss()
            onOpenOrder(id)
        },
        onDoSale = {
            vm.agtDoSale.value = agtHandoff(o)
            dismiss()
            onDoSale()
        },
        onRx = { rx = o.str("prescription_url") },
    )
    rx?.let { AgtRxViewer(it, "Prescription · ${o.str("order_no")}", repo) { rx = null } }
}

@Composable
private fun AgtNewOrderDialog(
    o: JsonObject,
    repo: AgtRepository,
    qr: Bitmap?,
    loading: Boolean,
    onDismiss: () -> Unit,
    onOpen: () -> Unit,
    onDoSale: () -> Unit,
    onRx: () -> Unit,
) {
    val items = agtObjects(o, "items")
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(
            shape = RoundedCornerShape(14.dp),
            color = Color.White,
            modifier = Modifier.fillMaxWidth(0.94f).widthIn(max = 560.dp).heightIn(max = (LocalConfiguration.current.screenHeightDp * 0.9f).dp),
        ) {
            Column {
                Row(
                    Modifier.fillMaxWidth().background(PharmaTeal).padding(horizontal = 12.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Default.LocalShipping, null, Modifier.size(22.dp), tint = Color.White)
                    Column(Modifier.weight(1f).padding(start = 8.dp)) {
                        Text("New home delivery order", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = Color.White)
                        Text("A patient placed an order on AuraGharTak", style = MaterialTheme.typography.labelSmall, color = Color.White.copy(alpha = 0.85f))
                    }
                    IconButton(onClick = onDismiss, Modifier.size(30.dp)) { Icon(Icons.Default.Close, "Dismiss", tint = Color.White) }
                }
                Column(
                    Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState()).padding(12.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(40.dp).clip(CircleShape).background(PharmaTeal.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                            Text(o.str("patient_name").trim().take(1).uppercase().ifBlank { "P" }, color = PharmaTeal, fontWeight = FontWeight.Bold)
                        }
                        Column(Modifier.weight(1f).padding(start = 8.dp)) {
                            Text(o.str("patient_name"), style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                            Text(o.str("patient_contact"), style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
                        }
                        Text(
                            o.str("order_no"),
                            Modifier.clip(RoundedCornerShape(4.dp)).background(Color(0xFFF0FDFA)).padding(horizontal = 6.dp, vertical = 2.dp),
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = PharmaTeal,
                        )
                    }
                    if (o.str("patient_address").isNotBlank()) {
                        Text("Deliver to: ${o.str("patient_address")}", style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                    }
                    if (o.str("patient_note").isNotBlank()) {
                        Text("Note: ${o.str("patient_note")}", style = MaterialTheme.typography.labelMedium, color = Color(0xFF92400E))
                    }
                    Text(
                        "${items.size} product(s) · Estimated ${formatAmount2(o.amount("estimated_total"))}",
                        style = MaterialTheme.typography.labelLarge,
                        fontWeight = FontWeight.Bold,
                        color = PharmaTeal,
                    )
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.Top) {
                        if (o.str("prescription_url").isNotBlank()) {
                            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text("PRESCRIPTION", style = MaterialTheme.typography.labelSmall, color = PharmaMuted, fontWeight = FontWeight.SemiBold)
                                AgtPrescriptionThumb(o.str("prescription_url"), repo, onRx)
                            }
                        }
                        if (qr != null) {
                            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text("PAYMENT QR", style = MaterialTheme.typography.labelSmall, color = PharmaMuted, fontWeight = FontWeight.SemiBold)
                                Image(
                                    qr.asImageBitmap(),
                                    "Payment QR",
                                    Modifier.size(110.dp).clip(RoundedCornerShape(8.dp)).border(1.dp, PharmaBorder, RoundedCornerShape(8.dp)),
                                    contentScale = ContentScale.Fit,
                                )
                            }
                        }
                    }
                    if (loading) LinearProgressIndicator(Modifier.fillMaxWidth(), color = PharmaTeal)
                    AgtItemsTable(items, showStock = false)
                }
                HorizontalDivider(color = PharmaBorder)
                Row(Modifier.fillMaxWidth().padding(10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    PharmaOutlinedButton("Dismiss", Modifier.weight(1f), onClick = onDismiss)
                    PharmaOutlinedButton("Open order", Modifier.weight(1f), onClick = onOpen)
                    if (agtOpenForSale(o)) {
                        PharmaPrimaryButton("Do Sale", Modifier.weight(1f), onClick = onDoSale)
                    }
                }
            }
        }
    }
}

// ───────────────────────── Sales billing: AGT order sheet ─────────────────────────

/** Pending AGT orders for the bill's patient → select → Add to bill (web billing AGT modal). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun AgtBillOrdersSheet(
    orders: List<JsonObject>,
    selected: JsonObject?,
    repo: AgtRepository,
    loading: Boolean,
    adding: Boolean,
    onSelect: (JsonObject) -> Unit,
    onAdd: (JsonObject) -> Unit,
    onRx: (JsonObject) -> Unit,
    onDismiss: () -> Unit,
) {
    ModalBottomSheet(onDismissRequest = { if (!adding) onDismiss() }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 12.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.LocalShipping, null, Modifier.size(20.dp), tint = PharmaTeal)
                Text(
                    "Home delivery / AuraGharTak order",
                    Modifier.weight(1f).padding(start = 6.dp),
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    color = PharmaSlate,
                )
            }
            when {
                loading -> Box(Modifier.fillMaxWidth().padding(20.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                }
                orders.isEmpty() && selected == null -> Text(
                    "No pending AuraGharTak orders for this patient.",
                    style = MaterialTheme.typography.bodySmall,
                    color = PharmaMuted,
                )
                else -> {
                    if (orders.size > 1) {
                        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                            orders.forEach { ord ->
                                PoChip(
                                    "${ord.str("order_no")} · ${formatSaleDate(ord.str("entry_date"))}",
                                    selected?.jInt("id") == ord.jInt("id"),
                                ) { onSelect(ord) }
                            }
                        }
                    }
                    selected?.let { o ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(o.str("order_no"), Modifier.weight(1f), style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold, color = PharmaTeal)
                            AgtStatusBadge(o.str("status"))
                        }
                        if (o.str("patient_note").isNotBlank()) {
                            Text("Note: ${o.str("patient_note")}", style = MaterialTheme.typography.labelMedium, color = Color(0xFF92400E))
                        }
                        if (o.str("prescription_url").isNotBlank()) {
                            AgtPrescriptionThumb(o.str("prescription_url"), repo, { onRx(o) })
                        }
                        AgtItemsTable(agtObjects(o, "items"), showStock = true, showMoney = false)
                        if (!agtOpenForSale(o)) {
                            Text(
                                if (o.str("sale_invoice_no").isNotBlank()) "Already billed (${o.str("sale_invoice_no")})."
                                else "This order cannot be billed (status: ${o.str("status")}).",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.error,
                            )
                        }
                    }
                }
            }
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.End)) {
                PharmaOutlinedButton("Close", enabled = !adding, onClick = onDismiss)
                PharmaPrimaryButton(
                    if (adding) "Adding…" else "Add to bill",
                    enabled = !adding && selected != null && agtOpenForSale(selected),
                ) { selected?.let(onAdd) }
            }
            Spacer(Modifier.height(8.dp))
        }
    }
}
