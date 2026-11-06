package `in`.paperlesspharmacy.advanced.ui.screens

import android.content.Context
import android.content.Intent
import android.graphics.BitmapFactory
import android.net.Uri
import android.provider.OpenableColumns
import android.webkit.MimeTypeMap
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.MultipartFile
import `in`.paperlesspharmacy.advanced.data.arr
import `in`.paperlesspharmacy.advanced.data.bool
import `in`.paperlesspharmacy.advanced.data.jInt
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.str
import `in`.paperlesspharmacy.advanced.data.transaction.SupportRepository
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.CompactTextField
import `in`.paperlesspharmacy.advanced.ui.components.ErrorBanner
import `in`.paperlesspharmacy.advanced.ui.components.LoadingBlock
import `in`.paperlesspharmacy.advanced.ui.components.PharmaCard
import `in`.paperlesspharmacy.advanced.ui.components.PharmaOutlinedButton
import `in`.paperlesspharmacy.advanced.ui.components.PharmaPrimaryButton
import `in`.paperlesspharmacy.advanced.ui.theme.*
import `in`.paperlesspharmacy.advanced.util.DownloadNotifier
import `in`.paperlesspharmacy.advanced.util.ReportExcelWriter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private val SupportStatuses = listOf("Generated", "Pending", "In Process", "Resolved", "Rejected")
private val ImageExt = setOf("jpg", "jpeg", "png", "gif", "webp", "bmp")
private val DocExt = setOf("pdf", "doc", "docx", "xls", "xlsx", "csv", "txt", "zip", "rar", "ppt", "pptx")
private const val MaxImageMb = 5
private const val MaxDocMb = 10

private data class PickedSupportFile(
    val name: String,
    val bytes: ByteArray,
    val mime: String,
    val isImage: Boolean,
)

private fun supportStatusColor(status: String): Color = when (status.lowercase()) {
    "generated" -> Color(0xFF475569)
    "pending" -> Color(0xFFD97706)
    "in process" -> Color(0xFF0284C7)
    "resolved" -> Color(0xFF15803D)
    "rejected" -> Color(0xFFDC2626)
    else -> PharmaMuted
}

private fun supportLocked(t: JsonObject?): Boolean =
    (t?.str("status") ?: "").equals("Resolved", ignoreCase = true)

private fun supportAttachmentCount(t: JsonObject): Int {
    val shots = t.arr("screenshot_urls").size()
    val adminShots = t.arr("admin_screenshot_urls").size()
    val adminFiles = t.arr("admin_files").size()
    return shots + adminShots + adminFiles
}

private fun supportUrlList(obj: JsonObject, key: String): List<String> {
    val arr = obj.arr(key)
    return (0 until arr.size()).mapNotNull { arr.get(it)?.takeIf { e -> !e.isJsonNull }?.asString }
}

private fun supportFileList(obj: JsonObject, key: String): List<Pair<String, String>> {
    val arr = obj.arr(key)
    return (0 until arr.size()).mapNotNull {
        val f = arr.get(it)?.takeIf { e -> e.isJsonObject }?.asJsonObject ?: return@mapNotNull null
        val name = f.str("name").ifBlank { "attachment" }
        val url = f.str("url")
        if (url.isBlank()) null else name to url
    }
}

private fun readPickedFile(context: Context, uri: Uri): PickedSupportFile? {
    val name = context.contentResolver.query(uri, null, null, null, null)?.use { c ->
        val i = c.getColumnIndex(OpenableColumns.DISPLAY_NAME)
        if (c.moveToFirst() && i >= 0) c.getString(i) else null
    } ?: uri.lastPathSegment ?: "file"
    val bytes = context.contentResolver.openInputStream(uri)?.use { it.readBytes() } ?: return null
    val ext = name.substringAfterLast('.', "").lowercase()
    val mime = context.contentResolver.getType(uri)
        ?: MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext)
        ?: "application/octet-stream"
    return PickedSupportFile(name, bytes, mime, ext in ImageExt)
}

private fun validatePicks(
    current: List<PickedSupportFile>,
    incoming: List<PickedSupportFile>,
    images: Boolean,
    maxCount: Int,
): Pair<List<PickedSupportFile>, String?> {
    val next = current.toMutableList()
    val problems = mutableListOf<String>()
    val maxBytes = (if (images) MaxImageMb else MaxDocMb) * 1024L * 1024L
    for (f in incoming) {
        val ext = f.name.substringAfterLast('.', "").lowercase()
        if (images && ext !in ImageExt) {
            problems += "Unsupported file: ${f.name}"
            continue
        }
        if (!images && ext !in DocExt) {
            problems += "Unsupported file: ${f.name}"
            continue
        }
        if (f.bytes.size > maxBytes) {
            problems += "Too large (max ${if (images) MaxImageMb else MaxDocMb} MB): ${f.name}"
            continue
        }
        if (next.size >= maxCount) {
            problems += "Maximum $maxCount files allowed."
            break
        }
        next += f
    }
    return next to problems.joinToString(" ").ifBlank { null }
}

@Composable
fun SupportModuleScreen(vm: AppViewModel, onBack: () -> Unit) {
    val repo = remember { vm.support() }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val openTicketReq by vm.supportOpenTicket.collectAsState()

    var isAdmin by remember { mutableStateOf(false) }
    var tickets by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var limitedLog by remember { mutableStateOf(false) }
    var logLimit by remember { mutableIntStateOf(20) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var statusFilter by remember { mutableStateOf("") }
    var searchInput by remember { mutableStateOf("") }
    var activeSearch by remember { mutableStateOf("") }
    var newOpen by remember { mutableStateOf(false) }
    var viewTicket by remember { mutableStateOf<JsonObject?>(null) }
    var focusReply by remember { mutableStateOf(false) }

    fun load(silent: Boolean = false) {
        scope.launch {
            if (!silent) loading = true
            error = null
            runCatching {
                withContext(Dispatchers.IO) {
                    repo.list(statusFilter, if (isAdmin || activeSearch.isNotBlank()) activeSearch else "")
                }
            }.onSuccess { res ->
                isAdmin = res.isAdmin
                tickets = res.tickets
                limitedLog = res.limitedLog
                logLimit = res.logLimit ?: 20
                viewTicket?.let { cur ->
                    tickets.find { it.jInt("id") == cur.jInt("id") }?.let { viewTicket = it }
                }
            }.onFailure {
                if (!silent) error = it.message ?: "Could not load tickets."
            }
            loading = false
        }
    }

    LaunchedEffect(statusFilter, activeSearch) { load() }
    LaunchedEffect(Unit) {
        while (isActive) {
            delay(15_000)
            load(silent = true)
        }
    }
    LaunchedEffect(openTicketReq) {
        val id = openTicketReq.first
        if (id <= 0) return@LaunchedEffect
        focusReply = openTicketReq.second
        val existing = tickets.find { it.jInt("id") == id }
        if (existing != null) {
            viewTicket = existing
            vm.clearSupportOpenTicket()
        } else {
            load(silent = true)
            delay(400)
            tickets.find { it.jInt("id") == id }?.let { viewTicket = it }
            vm.clearSupportOpenTicket()
        }
    }

    val openCount = tickets.count {
        val s = it.str("status")
        s == "In Process" || s == "Pending" || s == "Generated"
    }
    val resolvedCount = tickets.count { it.str("status").equals("Resolved", true) }
    val rejectedCount = tickets.count { it.str("status").equals("Rejected", true) }

    Column(
        Modifier.fillMaxSize().padding(CompactScreenPadding),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        PoHeader(
            "Support Center",
            if (isAdmin) "Manage client tickets, status, remarks and chat"
            else "Raise tickets and chat with the support team",
            onBack,
        ) {
            IconButton(onClick = { load() }) {
                Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal)
            }
            PharmaPrimaryButton("+ New", Modifier.heightIn(max = 34.dp)) { newOpen = true }
        }
        error?.let { ErrorBanner(it) { error = null } }
        toast?.let { PoToast(it) { toast = null } }

        CareKpiGrid(
            listOf(
                CareKpiItem("Tickets", tickets.size.toString(), Color(0xFF475569), Icons.Default.ConfirmationNumber),
                CareKpiItem("Open", openCount.toString(), Color(0xFF0284C7), Icons.Default.HourglassTop),
                CareKpiItem("Resolved", resolvedCount.toString(), Color(0xFF15803D), Icons.Default.CheckCircle),
                CareKpiItem("Rejected", rejectedCount.toString(), Color(0xFFDC2626), Icons.Default.Cancel),
            ),
        )

        PharmaCard {
            if (isAdmin) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    CompactTextField(
                        searchInput,
                        { searchInput = it },
                        "Client no / medical / ticket",
                        modifier = Modifier.weight(1f),
                    )
                    PharmaPrimaryButton("Search", Modifier.heightIn(max = 34.dp)) {
                        activeSearch = searchInput.trim()
                    }
                    PharmaOutlinedButton("Recent", enabled = activeSearch.isNotBlank(), modifier = Modifier.heightIn(max = 34.dp)) {
                        searchInput = ""
                        activeSearch = ""
                    }
                }
                Spacer(Modifier.height(6.dp))
            }
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                item { PoChip("All Status", statusFilter.isEmpty()) { statusFilter = "" } }
                items(SupportStatuses) { s -> PoChip(s, statusFilter == s) { statusFilter = s } }
            }
            if (isAdmin && limitedLog && activeSearch.isBlank()) {
                Text(
                    "Showing latest $logLimit tickets — search to find a client's older tickets.",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    modifier = Modifier.padding(top = 6.dp),
                )
            } else if (isAdmin && activeSearch.isNotBlank()) {
                Text(
                    "Search results for \"$activeSearch\"",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    modifier = Modifier.padding(top = 6.dp),
                )
            }
        }

        if (loading && tickets.isEmpty()) {
            LoadingBlock()
        } else if (tickets.isEmpty()) {
            PharmaCard {
                Text("No tickets found.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                Spacer(Modifier.height(8.dp))
                PharmaPrimaryButton("Raise your first ticket") { newOpen = true }
            }
        } else {
            LazyColumn(
                Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                items(tickets, key = { it.jInt("id") }) { t ->
                    SupportTicketRow(
                        ticket = t,
                        isAdmin = isAdmin,
                        onOpen = { viewTicket = t; focusReply = false },
                    )
                }
            }
        }
    }

    if (newOpen) {
        NewSupportTicketDialog(
            repo = repo,
            onDismiss = { newOpen = false },
            onCreated = { no ->
                newOpen = false
                toast = "Ticket $no raised. Status: In Process."
                load()
            },
        )
    }

    viewTicket?.let { t ->
        SupportTicketDetailDialog(
            repo = repo,
            ticket = t,
            isAdmin = isAdmin,
            focusReply = focusReply,
            onClose = { viewTicket = null; focusReply = false },
            onChanged = { updated ->
                viewTicket = updated
                load(silent = true)
            },
            onToast = { toast = it },
        )
    }
}

@Composable
private fun SupportTicketRow(ticket: JsonObject, isAdmin: Boolean, onOpen: () -> Unit) {
    val locked = supportLocked(ticket)
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = if (locked) Color(0xFFF8FAFC) else Color.White,
        border = BorderStroke(1.dp, PharmaBorder),
        modifier = Modifier.fillMaxWidth().clickable(onClick = onOpen),
    ) {
        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(ticket.str("ticket_no"), fontWeight = FontWeight.SemiBold, color = PharmaSlate, style = MaterialTheme.typography.titleSmall)
                    Text(careDateTime(ticket.str("created_at")), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
                StatusBadge(ticket.str("status"))
                IconButton(onClick = onOpen, modifier = Modifier.size(32.dp)) {
                    Icon(Icons.Default.Visibility, "Open", tint = PharmaTeal, modifier = Modifier.size(18.dp))
                }
            }
            if (isAdmin) {
                Text(
                    "#${ticket.str("client_no")} · ${ticket.str("medical_name").ifBlank { "—" }}",
                    style = MaterialTheme.typography.labelMedium,
                    color = PharmaSlate,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            Text(ticket.str("subject"), style = MaterialTheme.typography.bodySmall, color = PharmaSlate, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(ticket.str("query_text").ifBlank { "—" }, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (ticket.str("admin_remark").isNotBlank()) {
                Text(
                    "Remark: ${ticket.str("admin_remark")}",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(
                    "📎 ${supportAttachmentCount(ticket)}",
                    style = MaterialTheme.typography.labelSmall,
                    color = if (supportAttachmentCount(ticket) > 0) PharmaSlate else PharmaMuted,
                )
                Text(
                    "💬 ${ticket.jInt("reply_count")}",
                    style = MaterialTheme.typography.labelSmall,
                    color = if (ticket.jInt("reply_count") > 0) PharmaTealDark else PharmaMuted,
                )
            }
        }
    }
}

@Composable
private fun StatusBadge(status: String) {
    val c = supportStatusColor(status)
    Text(
        status.ifBlank { "—" },
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.SemiBold,
        color = c,
        modifier = Modifier
            .clip(RoundedCornerShape(20.dp))
            .background(c.copy(alpha = 0.12f))
            .padding(horizontal = 8.dp, vertical = 3.dp),
    )
}

@Composable
private fun NewSupportTicketDialog(
    repo: SupportRepository,
    onDismiss: () -> Unit,
    onCreated: (String) -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var subject by remember { mutableStateOf("") }
    var query by remember { mutableStateOf("") }
    var shots by remember { mutableStateOf<List<PickedSupportFile>>(emptyList()) }
    var err by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }

    val imagePicker = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        val picked = uris.mapNotNull { readPickedFile(context, it) }
        val (next, problem) = validatePicks(shots, picked, images = true, maxCount = 8)
        shots = next
        err = problem
    }

    Dialog(onDismissRequest = { if (!saving) onDismiss() }, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(
            shape = RoundedCornerShape(14.dp),
            color = Color.White,
            modifier = Modifier.fillMaxWidth(0.96f).fillMaxHeight(0.92f),
        ) {
            Column(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Raise New Ticket", fontWeight = FontWeight.Bold, color = PharmaSlate, modifier = Modifier.weight(1f))
                    IconButton(onClick = { if (!saving) onDismiss() }) {
                        Icon(Icons.Default.Close, null, tint = PharmaMuted)
                    }
                }
                CompactTextField(subject, { if (it.length <= 500) subject = it }, "Subject / Query Title *")
                OutlinedTextField(
                    value = query,
                    onValueChange = { query = it },
                    label = { Text("Query Details *") },
                    minLines = 5,
                    modifier = Modifier.fillMaxWidth().weight(1f, fill = false),
                    shape = RoundedCornerShape(8.dp),
                )
                Text("Screenshots (max 8 images, 5 MB each)", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                PharmaOutlinedButton("Add screenshots") {
                    imagePicker.launch(arrayOf("image/*"))
                }
                FileChipRow(shots) { i -> shots = shots.filterIndexed { idx, _ -> idx != i } }
                err?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Color(0xFFDC2626)) }
                Text(
                    "Submit marks the ticket In Process. Default remark: resolved within 24 hrs.",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                    PharmaOutlinedButton("Cancel", modifier = Modifier.weight(1f), enabled = !saving, onClick = onDismiss)
                    PharmaPrimaryButton(
                        if (saving) "Submitting…" else "Submit Ticket",
                        modifier = Modifier.weight(1f),
                        enabled = !saving,
                    ) {
                        val s = subject.trim()
                        val q = query.trim()
                        when {
                            s.isBlank() -> err = "Please enter subject / query title."
                            q.isBlank() -> err = "Please enter query details."
                            else -> {
                                saving = true
                                err = null
                                scope.launch {
                                    runCatching {
                                        withContext(Dispatchers.IO) {
                                            repo.create(
                                                s,
                                                q,
                                                shots.map {
                                                    MultipartFile("screenshots[]", it.name, it.bytes, it.mime)
                                                },
                                            )
                                        }
                                    }.onSuccess { data ->
                                        onCreated(data.str("ticket_no").ifBlank { "#${data.jInt("id")}" })
                                    }.onFailure {
                                        err = it.message ?: "Could not raise ticket."
                                    }
                                    saving = false
                                }
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
private fun SupportTicketDetailDialog(
    repo: SupportRepository,
    ticket: JsonObject,
    isAdmin: Boolean,
    focusReply: Boolean,
    onClose: () -> Unit,
    onChanged: (JsonObject) -> Unit,
    onToast: (String) -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var current by remember(ticket.jInt("id")) { mutableStateOf(ticket) }
    var replies by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var canReply by remember { mutableStateOf(false) }
    var loadingReplies by remember { mutableStateOf(true) }
    var replyText by remember { mutableStateOf("") }
    var replyShots by remember { mutableStateOf<List<PickedSupportFile>>(emptyList()) }
    var replyFiles by remember { mutableStateOf<List<PickedSupportFile>>(emptyList()) }
    var replyError by remember { mutableStateOf<String?>(null) }
    var sending by remember { mutableStateOf(false) }
    var adminStatus by remember { mutableStateOf(ticket.str("status")) }
    var adminRemark by remember { mutableStateOf(ticket.str("admin_remark")) }
    var adminShots by remember { mutableStateOf<List<PickedSupportFile>>(emptyList()) }
    var adminFiles by remember { mutableStateOf<List<PickedSupportFile>>(emptyList()) }
    var adminError by remember { mutableStateOf<String?>(null) }
    var updating by remember { mutableStateOf(false) }
    var previewUrl by remember { mutableStateOf<String?>(null) }
    val imageCache = remember { mutableStateMapOf<String, ByteArray>() }

    fun resetAdmin(t: JsonObject) {
        adminStatus = t.str("status")
        adminRemark = t.str("admin_remark")
        adminShots = emptyList()
        adminFiles = emptyList()
        adminError = null
    }

    fun preload(urls: List<String>) {
        urls.forEach { url ->
            if (url.isBlank() || imageCache.containsKey(url)) return@forEach
            scope.launch {
                runCatching {
                    withContext(Dispatchers.IO) { repo.fileBytes(url) }
                }.onSuccess { imageCache[url] = it }
            }
        }
    }

    fun loadReplies(silent: Boolean = false) {
        val id = current.jInt("id")
        scope.launch {
            if (!silent) loadingReplies = true
            runCatching {
                withContext(Dispatchers.IO) { repo.conversation(id) }
            }.onSuccess { conv ->
                val prevStatus = current.str("status")
                current = conv.ticket
                onChanged(conv.ticket)
                if (prevStatus != conv.ticket.str("status") && !updating) resetAdmin(conv.ticket)
                replies = conv.replies
                canReply = conv.canReply
                preload(supportUrlList(conv.ticket, "screenshot_urls"))
                preload(supportUrlList(conv.ticket, "admin_screenshot_urls"))
                conv.replies.forEach { preload(supportUrlList(it, "screenshot_urls")) }
            }
            loadingReplies = false
        }
    }

    LaunchedEffect(ticket.jInt("id")) {
        current = ticket
        resetAdmin(ticket)
        loadReplies()
    }
    LaunchedEffect(ticket.jInt("id")) {
        while (isActive) {
            delay(5_000)
            if (!sending) loadReplies(silent = true)
        }
    }

    val replyShotPicker = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        val picked = uris.mapNotNull { readPickedFile(context, it) }
        val (next, problem) = validatePicks(replyShots, picked, images = true, maxCount = 5)
        replyShots = next
        replyError = problem
    }
    val replyFilePicker = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        val picked = uris.mapNotNull { readPickedFile(context, it) }
        val (next, problem) = validatePicks(replyFiles, picked, images = false, maxCount = 5)
        replyFiles = next
        replyError = problem
    }
    val adminShotPicker = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        val picked = uris.mapNotNull { readPickedFile(context, it) }
        val (next, problem) = validatePicks(adminShots, picked, images = true, maxCount = 8)
        adminShots = next
        adminError = problem
    }
    val adminFilePicker = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        val picked = uris.mapNotNull { readPickedFile(context, it) }
        val (next, problem) = validatePicks(adminFiles, picked, images = false, maxCount = 8)
        adminFiles = next
        adminError = problem
    }

    fun downloadFile(name: String, url: String) {
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    val bytes = repo.fileBytes(url)
                    val mime = MimeTypeMap.getSingleton()
                        .getMimeTypeFromExtension(name.substringAfterLast('.', "").lowercase())
                        ?: "application/octet-stream"
                    val uri = ReportExcelWriter.saveToDownloads(context, name, bytes, mime)
                    DownloadNotifier.notifyDownloaded(context, uri, name, mime, "Attachment downloaded")
                    uri to mime
                }
            }.onSuccess { (uri, mime) ->
                onToast("Saved $name")
                runCatching {
                    context.startActivity(
                        Intent(Intent.ACTION_VIEW).apply {
                            setDataAndType(uri, mime)
                            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
                        },
                    )
                }
            }.onFailure { onToast(it.message ?: "Could not download file.") }
        }
    }

    Dialog(
        onDismissRequest = onClose,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Surface(
            shape = RoundedCornerShape(14.dp),
            color = Color.White,
            modifier = Modifier.fillMaxWidth(0.98f).fillMaxHeight(0.95f),
        ) {
            Column(Modifier.fillMaxSize()) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .background(PharmaTealDark)
                        .padding(horizontal = 10.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text(current.str("ticket_no"), color = Color.White, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleSmall)
                            StatusBadge(current.str("status"))
                        }
                        Text(
                            current.str("subject"),
                            color = Color.White.copy(alpha = 0.85f),
                            style = MaterialTheme.typography.labelSmall,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                    IconButton(onClick = onClose) {
                        Icon(Icons.Default.Close, "Close", tint = Color.White)
                    }
                }

                Column(
                    Modifier
                        .weight(1f)
                        .verticalScroll(rememberScrollState())
                        .padding(10.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    if (isAdmin) {
                        Text("#${current.str("client_no")} · ${current.str("medical_name").ifBlank { "—" }}", style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                        Text("Contact: ${current.str("contact_no").ifBlank { "—" }}", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    }
                    Text(
                        "Created: ${current.str("created_by").ifBlank { "—" }} · ${careDateTime(current.str("created_at"))}",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                    )
                    Text("Query Details", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium)
                    Text(
                        current.str("query_text").ifBlank { "—" },
                        style = MaterialTheme.typography.bodySmall,
                        color = PharmaSlate,
                        modifier = Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(8.dp))
                            .background(Color(0xFFF8FAFC))
                            .padding(8.dp),
                    )
                    Text("Support Remark", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium)
                    Text(
                        current.str("admin_remark").ifBlank { "—" },
                        style = MaterialTheme.typography.bodySmall,
                        color = PharmaSlate,
                        modifier = Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(8.dp))
                            .background(Color(0xFFECFDF5))
                            .padding(8.dp),
                    )

                    val clientShots = supportUrlList(current, "screenshot_urls")
                    if (clientShots.isNotEmpty()) {
                        Text("Client Screenshots", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium)
                        ThumbRow(clientShots, imageCache) { previewUrl = it }
                    }
                    val adminShotUrls = supportUrlList(current, "admin_screenshot_urls")
                    val adminFileMetas = supportFileList(current, "admin_files")
                    if (adminShotUrls.isNotEmpty() || adminFileMetas.isNotEmpty()) {
                        Text("Support Attachments", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium)
                        ThumbRow(adminShotUrls, imageCache) { previewUrl = it }
                        adminFileMetas.forEach { (name, url) ->
                            TextButton(onClick = { downloadFile(name, url) }) {
                                Icon(Icons.Default.AttachFile, null, Modifier.size(14.dp), tint = PharmaTeal)
                                Spacer(Modifier.width(4.dp))
                                Text(name, style = MaterialTheme.typography.labelSmall, color = PharmaTealDark)
                            }
                        }
                    }

                    if (isAdmin) {
                        PharmaCard {
                            Text("Support Action", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium, color = PharmaTealDark)
                            if (supportLocked(current)) {
                                Text(
                                    "Resolved tickets are locked. The client can reply to reopen it.",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaMuted,
                                )
                            } else {
                                var statusOpen by remember { mutableStateOf(false) }
                                ExposedDropdownMenuBox(expanded = statusOpen, onExpandedChange = { statusOpen = !statusOpen }) {
                                    OutlinedTextField(
                                        value = adminStatus,
                                        onValueChange = {},
                                        readOnly = true,
                                        label = { Text("Status") },
                                        trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(statusOpen) },
                                        modifier = Modifier.menuAnchor().fillMaxWidth(),
                                    )
                                    ExposedDropdownMenu(expanded = statusOpen, onDismissRequest = { statusOpen = false }) {
                                        SupportStatuses.forEach { s ->
                                            DropdownMenuItem(text = { Text(s) }, onClick = {
                                                adminStatus = s
                                                statusOpen = false
                                            })
                                        }
                                    }
                                }
                                OutlinedTextField(
                                    value = adminRemark,
                                    onValueChange = { adminRemark = it },
                                    label = { Text("Remark for client") },
                                    minLines = 3,
                                    modifier = Modifier.fillMaxWidth(),
                                )
                                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    PharmaOutlinedButton("Screenshot") { adminShotPicker.launch(arrayOf("image/*")) }
                                    PharmaOutlinedButton("File") {
                                        adminFilePicker.launch(
                                            arrayOf(
                                                "application/pdf",
                                                "application/msword",
                                                "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                                                "application/vnd.ms-excel",
                                                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                                                "text/*",
                                                "application/zip",
                                                "*/*",
                                            ),
                                        )
                                    }
                                }
                                FileChipRow(adminShots + adminFiles) { i ->
                                    val all = adminShots + adminFiles
                                    val f = all.getOrNull(i) ?: return@FileChipRow
                                    if (f.isImage) adminShots = adminShots.filter { it !== f }
                                    else adminFiles = adminFiles.filter { it !== f }
                                }
                                adminError?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Color(0xFFDC2626)) }
                                PharmaPrimaryButton(if (updating) "Saving…" else "Update Ticket", enabled = !updating) {
                                    if (adminStatus.isBlank()) {
                                        adminError = "Select a status."
                                        return@PharmaPrimaryButton
                                    }
                                    updating = true
                                    adminError = null
                                    scope.launch {
                                        runCatching {
                                            withContext(Dispatchers.IO) {
                                                repo.updateStatus(
                                                    current.jInt("id"),
                                                    adminStatus,
                                                    adminRemark,
                                                    adminShots.map { MultipartFile("admin_screenshots[]", it.name, it.bytes, it.mime) },
                                                    adminFiles.map { MultipartFile("admin_files[]", it.name, it.bytes, it.mime) },
                                                )
                                            }
                                        }.onSuccess { updated ->
                                            current = updated
                                            resetAdmin(updated)
                                            onChanged(updated)
                                            onToast("Ticket ${updated.str("ticket_no")} updated.")
                                            loadReplies(silent = true)
                                        }.onFailure {
                                            adminError = it.message ?: "Could not update ticket."
                                        }
                                        updating = false
                                    }
                                }
                            }
                        }
                    }

                    Text("Ticket Conversation", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium)
                    if (loadingReplies && replies.isEmpty()) {
                        CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = PharmaTeal)
                    } else if (replies.isEmpty()) {
                        Text("No messages yet. Start the conversation below.", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    }
                    replies.forEach { r ->
                        val adminBubble = r.bool("is_admin")
                        Column(
                            Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(10.dp))
                                .background(if (adminBubble) Color(0xFFECFDF5) else Color(0xFFF1F5F9))
                                .padding(8.dp),
                        ) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(
                                    r.str("sender_name").ifBlank { if (adminBubble) "Support" else "Client" },
                                    fontWeight = FontWeight.SemiBold,
                                    style = MaterialTheme.typography.labelMedium,
                                    color = PharmaSlate,
                                )
                                Text(careDateTime(r.str("created_at")), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                            }
                            if (r.str("message").isNotBlank()) {
                                Text(r.str("message"), style = MaterialTheme.typography.bodySmall, color = PharmaSlate)
                            }
                            ThumbRow(supportUrlList(r, "screenshot_urls"), imageCache) { previewUrl = it }
                            supportFileList(r, "files").forEach { (name, url) ->
                                TextButton(onClick = { downloadFile(name, url) }) {
                                    Icon(Icons.Default.AttachFile, null, Modifier.size(14.dp), tint = PharmaTeal)
                                    Spacer(Modifier.width(4.dp))
                                    Text(name, style = MaterialTheme.typography.labelSmall, color = PharmaTealDark)
                                }
                            }
                        }
                    }
                }

                HorizontalDivider(color = PharmaBorder)
                if (canReply) {
                    Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        if (!isAdmin && supportLocked(current)) {
                            Text(
                                "This ticket is resolved. Send a reply to reopen it for support.",
                                style = MaterialTheme.typography.labelSmall,
                                color = PharmaMuted,
                            )
                        }
                        OutlinedTextField(
                            value = replyText,
                            onValueChange = { replyText = it },
                            placeholder = { Text("Type your reply…") },
                            minLines = 2,
                            modifier = Modifier.fillMaxWidth(),
                            shape = RoundedCornerShape(8.dp),
                        )
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                            IconButton(onClick = { replyShotPicker.launch(arrayOf("image/*")) }) {
                                Icon(Icons.Default.PhotoCamera, "Screenshot", tint = PharmaTeal)
                            }
                            IconButton(onClick = {
                                replyFilePicker.launch(arrayOf("application/pdf", "text/*", "*/*"))
                            }) {
                                Icon(Icons.Default.AttachFile, "File", tint = PharmaTeal)
                            }
                            Spacer(Modifier.weight(1f))
                            PharmaPrimaryButton(if (sending) "Sending…" else "Send", enabled = !sending) {
                                if (replyText.isBlank() && replyShots.isEmpty() && replyFiles.isEmpty()) {
                                    replyError = "Enter a message or attach a file."
                                    return@PharmaPrimaryButton
                                }
                                sending = true
                                replyError = null
                                scope.launch {
                                    runCatching {
                                        withContext(Dispatchers.IO) {
                                            repo.postReply(
                                                current.jInt("id"),
                                                replyText.trim(),
                                                replyShots.map { MultipartFile("reply_screenshots[]", it.name, it.bytes, it.mime) },
                                                replyFiles.map { MultipartFile("reply_files[]", it.name, it.bytes, it.mime) },
                                            )
                                        }
                                    }.onSuccess { res ->
                                        replyText = ""
                                        replyShots = emptyList()
                                        replyFiles = emptyList()
                                        current = res.ticket
                                        onChanged(res.ticket)
                                        if (res.reopened) {
                                            resetAdmin(res.ticket)
                                            onToast("Reply sent — ticket reopened for support.")
                                        }
                                        loadReplies(silent = true)
                                    }.onFailure {
                                        replyError = it.message ?: "Could not send reply."
                                    }
                                    sending = false
                                }
                            }
                        }
                        FileChipRow(replyShots + replyFiles) { i ->
                            val all = replyShots + replyFiles
                            val f = all.getOrNull(i) ?: return@FileChipRow
                            if (f.isImage) replyShots = replyShots.filter { it !== f }
                            else replyFiles = replyFiles.filter { it !== f }
                        }
                        replyError?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Color(0xFFDC2626)) }
                    }
                } else {
                    Text(
                        if (isAdmin) "This ticket is closed — chat is locked."
                        else "This ticket is closed and cannot be replied to.",
                        style = MaterialTheme.typography.labelSmall,
                        color = PharmaMuted,
                        modifier = Modifier.padding(12.dp),
                    )
                }
            }
        }
    }

    previewUrl?.let { url ->
        Dialog(onDismissRequest = { previewUrl = null }) {
            Surface(shape = RoundedCornerShape(12.dp), color = Color.Black) {
                Box(Modifier.fillMaxWidth().heightIn(max = 480.dp)) {
                    val bytes = imageCache[url]
                    val bmp = bytes?.let {
                        runCatching { BitmapFactory.decodeByteArray(it, 0, it.size)?.asImageBitmap() }.getOrNull()
                    }
                    if (bmp != null) {
                        Image(bmp, "Screenshot", Modifier.fillMaxWidth(), contentScale = ContentScale.Fit)
                    } else {
                        CircularProgressIndicator(Modifier.align(Alignment.Center), color = Color.White)
                    }
                    IconButton(onClick = { previewUrl = null }, modifier = Modifier.align(Alignment.TopEnd)) {
                        Icon(Icons.Default.Close, null, tint = Color.White)
                    }
                }
            }
        }
    }
}

@Composable
private fun ThumbRow(
    urls: List<String>,
    cache: Map<String, ByteArray>,
    onOpen: (String) -> Unit,
) {
    if (urls.isEmpty()) return
    LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        items(urls) { url ->
            val bytes = cache[url]
            val bmp = bytes?.let {
                runCatching {
                    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
                    BitmapFactory.decodeByteArray(it, 0, it.size, bounds)
                    var sample = 1
                    while (bounds.outWidth / sample > 240 || bounds.outHeight / sample > 240) sample *= 2
                    BitmapFactory.decodeByteArray(it, 0, it.size, BitmapFactory.Options().apply { inSampleSize = sample })
                        ?.asImageBitmap()
                }.getOrNull()
            }
            Box(
                Modifier
                    .size(64.dp)
                    .clip(RoundedCornerShape(8.dp))
                    .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
                    .clickable { onOpen(url) },
                contentAlignment = Alignment.Center,
            ) {
                if (bmp != null) Image(bmp, null, Modifier.fillMaxSize(), contentScale = ContentScale.Crop)
                else CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp, color = PharmaTeal)
            }
        }
    }
}

@Composable
private fun FileChipRow(files: List<PickedSupportFile>, onRemove: (Int) -> Unit) {
    if (files.isEmpty()) return
    LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        items(files.size) { i ->
            val f = files[i]
            AssistChip(
                onClick = { onRemove(i) },
                label = {
                    Text(
                        f.name,
                        style = MaterialTheme.typography.labelSmall,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                },
                leadingIcon = {
                    Icon(
                        if (f.isImage) Icons.Default.Image else Icons.Default.InsertDriveFile,
                        null,
                        Modifier.size(14.dp),
                    )
                },
                trailingIcon = { Icon(Icons.Default.Close, null, Modifier.size(14.dp)) },
            )
        }
    }
}

/** Global popup for support poll notifications (web support-popup parity). */
@Composable
fun BoxScope.SupportNotifyHost(vm: AppViewModel, onOpenSupport: (ticketId: Int, focusReply: Boolean) -> Unit) {
    val repo = remember { vm.support() }
    var since by remember { mutableStateOf("") }
    var queue by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var active by remember { mutableStateOf<JsonObject?>(null) }
    var isAdmin by remember { mutableStateOf(false) }
    val seen = remember { mutableSetOf<String>() }

    LaunchedEffect(Unit) {
        while (isActive) {
            runCatching {
                withContext(Dispatchers.IO) { repo.poll(since) }
            }.onSuccess { res ->
                isAdmin = res.isAdmin
                if (res.serverTime.isNotBlank()) since = res.serverTime
                var fresh = false
                res.notifications.forEach { n ->
                    val key = n.str("key")
                    if (key.isBlank() || seen.contains(key)) return@forEach
                    seen.add(key)
                    queue = queue + n
                    fresh = true
                }
                if (fresh && active == null) {
                    active = queue.firstOrNull()
                    queue = queue.drop(1)
                }
            }
            delay(15_000)
        }
    }

    fun dismiss() {
        active = queue.firstOrNull()
        queue = queue.drop(1)
    }

    active?.let { n ->
        val type = n.str("type")
        val ticket = n.obj("ticket")
        val reply = n.obj("reply")
        val badge = when (type) {
            "new_ticket" -> "New Support Ticket"
            "ticket_reply" -> "New Ticket Reply"
            else -> "Ticket Status Updated"
        }
        val title = when (type) {
            "new_ticket" -> "A client needs assistance"
            "ticket_reply" -> "There is a new message on a support ticket"
            else -> "Your ticket was updated by support"
        }
        val body = when (type) {
            "ticket_reply" -> reply.str("message")
            "status_update" -> ticket.str("admin_remark")
            else -> ticket.str("query_text")
        }
        val canReply = !isAdmin && ticket.jInt("id") > 0 &&
            !ticket.str("status").equals("Rejected", ignoreCase = true)

        Surface(
            shape = RoundedCornerShape(14.dp),
            color = Color.White,
            shadowElevation = 10.dp,
            border = BorderStroke(1.dp, PharmaBorder),
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .padding(12.dp)
                .fillMaxWidth()
                .widthIn(max = 420.dp),
        ) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.SupportAgent, null, tint = PharmaTeal, modifier = Modifier.size(22.dp))
                    Spacer(Modifier.width(8.dp))
                    Column(Modifier.weight(1f)) {
                        Text(badge, style = MaterialTheme.typography.labelSmall, color = PharmaTealDark, fontWeight = FontWeight.SemiBold)
                        Text(title, style = MaterialTheme.typography.titleSmall, color = PharmaSlate, fontWeight = FontWeight.SemiBold)
                    }
                    IconButton(onClick = { dismiss() }) {
                        Icon(Icons.Default.Close, null, tint = PharmaMuted)
                    }
                }
                Text(
                    "${ticket.str("ticket_no").ifBlank { "#${ticket.jInt("id")}" }} · ${ticket.str("status")}",
                    style = MaterialTheme.typography.labelMedium,
                    color = PharmaSlate,
                )
                if (type != "status_update" && ticket.str("medical_name").isNotBlank()) {
                    Text(ticket.str("medical_name"), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                }
                if (ticket.str("subject").isNotBlank()) {
                    Text(ticket.str("subject"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium)
                }
                if (body.isNotBlank()) {
                    Text(body, style = MaterialTheme.typography.labelSmall, color = PharmaMuted, maxLines = 3, overflow = TextOverflow.Ellipsis)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.fillMaxWidth()) {
                    PharmaOutlinedButton("Dismiss", modifier = Modifier.weight(1f)) { dismiss() }
                    if (canReply) {
                        PharmaOutlinedButton("Reply", modifier = Modifier.weight(1f)) {
                            val id = ticket.jInt("id")
                            dismiss()
                            onOpenSupport(id, true)
                        }
                    }
                    PharmaPrimaryButton("Open", modifier = Modifier.weight(1f)) {
                        val id = ticket.jInt("id")
                        dismiss()
                        onOpenSupport(id, false)
                    }
                }
            }
        }
    }
}
