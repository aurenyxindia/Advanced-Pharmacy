package `in`.paperlesspharmacy.advanced.ui.screens

import android.graphics.BitmapFactory
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
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
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.transaction.ProfileRepository
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.*
import `in`.paperlesspharmacy.advanced.util.ContentUriReader
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate

private val UploadKeys = listOf("stamp", "logo", "scanner", "photo", "dl")

private fun uploadLabel(type: String): String = when (type) {
    "stamp" -> "Stamp & Sign"
    "logo" -> "Logo"
    "scanner" -> "Scanner (QR)"
    "photo" -> "Photo"
    "dl" -> "Drug License"
    else -> type
}

private fun mimeForName(name: String): String {
    val lower = name.lowercase()
    return when {
        lower.endsWith(".png") -> "image/png"
        lower.endsWith(".jpg") || lower.endsWith(".jpeg") -> "image/jpeg"
        lower.endsWith(".webp") -> "image/webp"
        lower.endsWith(".gif") -> "image/gif"
        lower.endsWith(".pdf") -> "application/pdf"
        else -> "application/octet-stream"
    }
}

private fun roleLabel(role: String): String = when (role) {
    "pharmacist" -> "Pharmacist"
    "purchase" -> "Non-Pharmacist"
    "cashier" -> "Cashier"
    "delivery" -> "Delivery staff"
    "branch_admin" -> "Branch Admin"
    "ho_admin" -> "HO Admin"
    else -> role.replace('_', ' ')
}

private fun roleToStaff(role: String): String = when (role) {
    "purchase", "cashier", "delivery" -> role
    else -> "pharmacist"
}

private fun formatAddress(p: JsonObject): String =
    listOf(p.str("address"), p.str("city"), p.str("state"), p.str("pincode"))
        .filter { it.isNotBlank() }
        .joinToString(", ")
        .ifBlank { "—" }

@Composable
fun ProfileScreen(vm: AppViewModel, onBack: () -> Unit) {
    val isAdmin = vm.hasFullAccess()
    var tab by remember { mutableIntStateOf(0) }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            PharmaOutlinedButton("Back", onClick = onBack)
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text("My Profile", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = PharmaSlate)
                Text(
                    "Pharmacy account, documents & user access",
                    style = MaterialTheme.typography.labelSmall,
                    color = PharmaMuted,
                )
            }
        }
        Spacer(Modifier.height(8.dp))
        if (isAdmin) {
            TabRow(
                selectedTabIndex = tab,
                containerColor = Color.Transparent,
                contentColor = PharmaTeal,
            ) {
                Tab(
                    selected = tab == 0,
                    onClick = { tab = 0 },
                    text = { Text("Profile", style = MaterialTheme.typography.labelLarge) },
                    icon = { Icon(Icons.Default.Person, null, Modifier.size(16.dp)) },
                )
                Tab(
                    selected = tab == 1,
                    onClick = { tab = 1 },
                    text = { Text("User Rights", style = MaterialTheme.typography.labelLarge) },
                    icon = { Icon(Icons.Default.Shield, null, Modifier.size(16.dp)) },
                )
            }
            Spacer(Modifier.height(8.dp))
        }
        when {
            !isAdmin || tab == 0 -> ProfileTab(vm)
            else -> UserRightsTab(vm)
        }
    }
}

@Composable
private fun ProfileTab(vm: AppViewModel) {
    val repo = remember { ProfileRepository(vm.api) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val isAdmin = vm.hasFullAccess()
    var loading by remember { mutableStateOf(true) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var success by remember { mutableStateOf<String?>(null) }
    var profile by remember { mutableStateOf<JsonObject?>(null) }
    var email by remember { mutableStateOf("") }
    var bankName by remember { mutableStateOf("") }
    var accountNo by remember { mutableStateOf("") }
    var ifsc by remember { mutableStateOf("") }
    var dob by remember { mutableStateOf("") }
    var hideMasterProducts by remember { mutableStateOf(false) }
    var hideMasterDistributors by remember { mutableStateOf(false) }
    var emailErr by remember { mutableStateOf<String?>(null) }
    var ifscErr by remember { mutableStateOf<String?>(null) }
    var previews by remember { mutableStateOf<Map<String, ByteArray?>>(emptyMap()) }
    var uploadBusy by remember { mutableStateOf<String?>(null) }
    var pendingUploadType by remember { mutableStateOf<String?>(null) }

    fun loadAssets(p: JsonObject) {
        val cid = p.jInt("client_id")
        if (cid <= 0) return
        val assets = p.obj("assets")
        scope.launch {
            val map = mutableMapOf<String, ByteArray?>()
            UploadKeys.forEach { key ->
                val flag = assets.get(key)
                val hasAsset = flag != null && !flag.isJsonNull && (
                    (flag.isJsonPrimitive && flag.asString.isNotBlank()) ||
                        (flag.isJsonPrimitive && flag.asJsonPrimitive.isBoolean && flag.asBoolean)
                    )
                if (hasAsset) {
                    map[key] = runCatching {
                        withContext(Dispatchers.IO) { repo.assetBytes(cid, key) }
                    }.getOrNull()
                }
            }
            previews = map
        }
    }

    fun load() {
        scope.launch {
            loading = true
            error = null
            runCatching { withContext(Dispatchers.IO) { repo.get() } }
                .onSuccess { p ->
                    profile = p
                    email = p.str("email")
                    bankName = p.str("bank_name")
                    accountNo = p.str("account_no")
                    ifsc = p.str("ifsc_code")
                    dob = p.str("dob").take(10)
                    hideMasterProducts = p.bool("hide_master_products")
                    hideMasterDistributors = p.bool("hide_master_distributors")
                    loadAssets(p)
                }
                .onFailure { error = careErr(it, "Could not load profile.") }
            loading = false
        }
    }

    LaunchedEffect(Unit) { load() }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        val type = pendingUploadType ?: return@rememberLauncherForActivityResult
        pendingUploadType = null
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            uploadBusy = type
            error = null
            success = null
            runCatching {
                withContext(Dispatchers.IO) {
                    val name = ContentUriReader.displayName(context, uri)
                    val bytes = ContentUriReader.readBytes(context, uri)
                    if (bytes.size > 50 * 1024 * 1024) {
                        throw IllegalArgumentException("File must be under 50 MB.")
                    }
                    repo.upload(type, name, bytes, mimeForName(name))
                    name to bytes
                }
            }.onSuccess { (_, bytes) ->
                previews = previews + (type to bytes)
                success = "${uploadLabel(type)} uploaded."
                profile?.let { loadAssets(it) }
            }.onFailure { error = careErr(it, "Upload failed.") }
            uploadBusy = null
        }
    }

    fun save() {
        emailErr = null
        ifscErr = null
        val em = email.trim()
        if (!Regex("""^[^\s@]+@[^\s@]+\.[^\s@]+$""").matches(em)) {
            emailErr = "Enter a valid email address."
            return
        }
        val ifscUp = ifsc.trim().uppercase()
        if (ifscUp.isNotBlank() && !Regex("""^[A-Z]{4}0[A-Z0-9]{6}$""").matches(ifscUp)) {
            ifscErr = "Invalid IFSC code."
            return
        }
        scope.launch {
            saving = true
            error = null
            success = null
            try {
                runCatching {
                    withContext(Dispatchers.IO) {
                        repo.save(
                            email = em,
                            bankName = bankName.trim(),
                            accountNo = accountNo.trim(),
                            ifsc = ifscUp,
                            dob = dob.takeIf { it.isNotBlank() },
                            hideMasterProducts = if (isAdmin) hideMasterProducts else null,
                            hideMasterDistributors = if (isAdmin) hideMasterDistributors else null,
                        )
                    }
                }.onSuccess {
                    success = "Profile saved successfully."
                    load()
                }.onFailure { error = careErr(it, "Save failed.") }
            } finally {
                saving = false
            }
        }
    }

    when {
        loading && profile == null -> LoadingBlock("Loading profile…")
        profile == null -> ErrorBanner(error ?: "Profile unavailable.") { load() }
        else -> {
            val p = profile!!
            LazyColumn(
                Modifier.fillMaxSize(),
                verticalArrangement = Arrangement.spacedBy(10.dp),
                contentPadding = PaddingValues(bottom = 24.dp),
            ) {
                item {
                    error?.let { ErrorBanner(it) { error = null } }
                    success?.let {
                        Surface(color = Color(0xFFD1FAE5), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth().padding(top = 4.dp)) {
                            Text(it, Modifier.padding(10.dp), style = MaterialTheme.typography.labelMedium, color = Color(0xFF166534))
                        }
                    }
                }
                item {
                    PharmaCard {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            ProfileAvatar(previews["photo"])
                            Column(Modifier.weight(1f)) {
                                Text("PHARMACY", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                Text(
                                    p.str("business_name").ifBlank { "—" },
                                    style = MaterialTheme.typography.titleMedium,
                                    fontWeight = FontWeight.Bold,
                                    color = PharmaSlate,
                                    maxLines = 2,
                                    overflow = TextOverflow.Ellipsis,
                                )
                                Text(
                                    listOf(p.str("contact_person"), p.str("phone"), p.str("email"))
                                        .filter { it.isNotBlank() }
                                        .joinToString(" · "),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaMuted,
                                    maxLines = 2,
                                    overflow = TextOverflow.Ellipsis,
                                )
                            }
                            IconButton(onClick = { load() }) {
                                Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal)
                            }
                        }
                    }
                }
                item {
                    PharmaCard {
                        Text("Contact & Address", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Text(
                            "Filled from registration — contact support to change locked fields.",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                            modifier = Modifier.padding(vertical = 4.dp),
                        )
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            PReadField("Client Name", p.str("business_name").ifBlank { "—" }, Modifier.weight(1f))
                            PReadField("Contact Person", p.str("contact_person").ifBlank { "—" }, Modifier.weight(1f))
                        }
                        Spacer(Modifier.height(6.dp))
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            PReadField("Phone", p.str("phone").ifBlank { "—" }, Modifier.weight(1f))
                            SalesFormField(
                                "Email *",
                                email,
                                { email = it; emailErr = null },
                                Modifier.weight(1f),
                                isError = emailErr != null,
                            )
                        }
                        emailErr?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.error) }
                        Spacer(Modifier.height(6.dp))
                        PDateField("Date of Birth", dob, { dob = it }, Modifier.fillMaxWidth())
                        Text("Shown on dashboard birthday", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                        Spacer(Modifier.height(6.dp))
                        PReadField("Address", formatAddress(p), Modifier.fillMaxWidth())
                        Spacer(Modifier.height(6.dp))
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            PReadField("City", p.str("city").ifBlank { "—" }, Modifier.weight(1f))
                            PReadField("State", p.str("state").ifBlank { "—" }, Modifier.weight(1f))
                            PReadField("Pincode", p.str("pincode").ifBlank { "—" }, Modifier.weight(1f))
                        }
                    }
                }
                item {
                    PharmaCard {
                        Text("Registration & Licenses", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Spacer(Modifier.height(6.dp))
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            PReadField("GSTIN", p.str("gstin").ifBlank { "—" }, Modifier.weight(1f))
                            PReadField("PAN", p.str("pan").ifBlank { "—" }, Modifier.weight(1f))
                        }
                        Spacer(Modifier.height(6.dp))
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            PReadField("FSSAI No.", p.str("fssai_no").ifBlank { "—" }, Modifier.weight(1f))
                            PReadField("Drug License No.", p.str("drug_license_no").ifBlank { "—" }, Modifier.weight(1f))
                        }
                    }
                }
                item {
                    PharmaCard {
                        Text("Bank Details", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Spacer(Modifier.height(6.dp))
                        SalesFormField("Bank Name", bankName, { bankName = it }, Modifier.fillMaxWidth())
                        Spacer(Modifier.height(6.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            SalesFormField("Account No.", accountNo, { accountNo = it }, Modifier.weight(1f))
                            SalesFormField(
                                "IFSC Code",
                                ifsc,
                                { ifsc = it.uppercase(); ifscErr = null },
                                Modifier.weight(1f),
                                isError = ifscErr != null,
                            )
                        }
                        ifscErr?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.error) }
                    }
                }
                if (isAdmin) {
                    item {
                        PharmaCard {
                            Text(
                                "Hide Master Catalogue",
                                style = MaterialTheme.typography.titleSmall,
                                fontWeight = FontWeight.SemiBold,
                                color = PharmaSlate,
                            )
                            Text(
                                "Hide the shared platform catalogue for this pharmacy. Master tabs stay available so you can add your own products and distributors — only those appear in lists and dropdowns everywhere.",
                                style = MaterialTheme.typography.labelSmall,
                                color = PharmaMuted,
                                modifier = Modifier.padding(top = 4.dp, bottom = 8.dp),
                            )
                            HideCatalogCheck(
                                title = "Product Master",
                                subtitle = "Show only products added by this pharmacy",
                                checked = hideMasterProducts,
                                onChange = { hideMasterProducts = it },
                            )
                            Spacer(Modifier.height(4.dp))
                            HideCatalogCheck(
                                title = "Distributor Master",
                                subtitle = "Show only distributors added by this pharmacy",
                                checked = hideMasterDistributors,
                                onChange = { hideMasterDistributors = it },
                            )
                        }
                    }
                }
                item {
                    PharmaPrimaryButton(
                        if (saving) "Saving…" else "Save Profile",
                        enabled = !saving,
                        modifier = Modifier.fillMaxWidth(),
                        onClick = { save() },
                    )
                }
                item {
                    PharmaCard {
                        Text("Documents & Images", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                        Text(
                            "Upload starts when you choose a file (images or PDF, up to 50 MB).",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                            modifier = Modifier.padding(vertical = 4.dp),
                        )
                        UploadKeys.chunked(2).forEach { row ->
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                row.forEach { key ->
                                    UploadCard(
                                        label = uploadLabel(key),
                                        bytes = previews[key],
                                        busy = uploadBusy == key,
                                        modifier = Modifier.weight(1f),
                                        onPick = {
                                            pendingUploadType = key
                                            picker.launch(arrayOf("image/*", "application/pdf"))
                                        },
                                    )
                                }
                                if (row.size == 1) Spacer(Modifier.weight(1f))
                            }
                            Spacer(Modifier.height(8.dp))
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun ProfileAvatar(bytes: ByteArray?) {
    val bmp = remember(bytes) {
        bytes?.let { runCatching { BitmapFactory.decodeByteArray(it, 0, it.size)?.asImageBitmap() }.getOrNull() }
    }
    Box(
        Modifier
            .size(64.dp)
            .clip(CircleShape)
            .background(Color(0xFFE0F2F1))
            .border(1.dp, PharmaBorder, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        if (bmp != null) {
            Image(bmp, "Photo", Modifier.fillMaxSize(), contentScale = ContentScale.Crop)
        } else {
            Icon(Icons.Default.Person, null, Modifier.size(32.dp), tint = PharmaTeal)
        }
    }
}

@Composable
private fun UploadCard(
    label: String,
    bytes: ByteArray?,
    busy: Boolean,
    modifier: Modifier = Modifier,
    onPick: () -> Unit,
) {
    val bmp = remember(bytes) {
        bytes?.let { runCatching { BitmapFactory.decodeByteArray(it, 0, it.size)?.asImageBitmap() }.getOrNull() }
    }
    Column(
        modifier
            .clip(RoundedCornerShape(8.dp))
            .border(1.dp, PharmaBorder, RoundedCornerShape(8.dp))
            .background(Color.White)
            .clickable(enabled = !busy, onClick = onPick)
            .padding(8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            Modifier
                .fillMaxWidth()
                .height(72.dp)
                .clip(RoundedCornerShape(6.dp))
                .background(Color(0xFFF8FAFC)),
            contentAlignment = Alignment.Center,
        ) {
            when {
                busy -> CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = PharmaTeal)
                bmp != null -> Image(bmp, label, Modifier.fillMaxSize(), contentScale = ContentScale.Fit)
                bytes != null -> {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Default.PictureAsPdf, null, tint = PharmaTeal)
                        Text("PDF on file", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                    }
                }
                else -> Icon(Icons.Default.CloudUpload, null, tint = PharmaMuted)
            }
        }
        Spacer(Modifier.height(6.dp))
        Text(label, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate, maxLines = 1)
        Text(if (busy) "Uploading…" else "Tap to upload", style = MaterialTheme.typography.labelSmall, color = PharmaTeal)
    }
}

// ───────────────────────── User Rights (admin) ─────────────────────────

private data class StaffForm(
    val id: Int? = null,
    val name: String = "",
    val regNo: String = "",
    val phone: String = "",
    val email: String = "",
    val password: String = "",
    val staffRole: String = "pharmacist",
    val branchId: Int = 0,
    val joiningDate: String = LocalDate.now().toString(),
    val address: String = "",
    val rights: List<RightsModuleRow> = emptyList(),
)

@Composable
private fun UserRightsTab(vm: AppViewModel) {
    val rightsRepo = remember { vm.rights }
    val scope = rememberCoroutineScope()
    var loading by remember { mutableStateOf(true) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var success by remember { mutableStateOf<String?>(null) }
    var search by remember { mutableStateOf("") }
    var users by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var modules by remember { mutableStateOf<List<RightsModuleRow>>(emptyList()) }
    var branches by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var form by remember { mutableStateOf<StaffForm?>(null) }
    var confirmDelete by remember { mutableStateOf<JsonObject?>(null) }

    fun reload() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                withContext(Dispatchers.IO) {
                    val mods = rightsRepo.loadModules()
                    val list = rightsRepo.loadUsers()
                    val br = rightsRepo.branches()
                    Triple(mods, list, br)
                }
            }.onSuccess { (mods, list, br) ->
                modules = mods
                users = list
                branches = br
            }.onFailure { error = careErr(it, "Could not load users.") }
            loading = false
        }
    }

    LaunchedEffect(Unit) { reload() }

    val filtered = remember(users, search) {
        val q = search.trim().lowercase()
        if (q.isEmpty()) users
        else users.filter {
            listOf(it.str("name"), it.str("email"), it.str("phone"), it.str("reg_no"), it.str("role"))
                .joinToString(" ")
                .lowercase()
                .contains(q)
        }
    }

    fun openCreate() {
        val defaultBranch = vm.user.value?.branchId ?: branches.firstOrNull()?.jInt("id") ?: 0
        form = StaffForm(
            branchId = defaultBranch,
            rights = modules.map { it.copy() },
        )
        success = null
        error = null
    }

    fun openEdit(u: JsonObject) {
        val existing = u.arr("rights").mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
        form = StaffForm(
            id = u.jInt("id"),
            name = u.str("name"),
            regNo = u.str("reg_no"),
            phone = u.str("phone"),
            email = u.str("email"),
            staffRole = roleToStaff(u.str("role")),
            branchId = u.jInt("branch_id"),
            joiningDate = u.str("joining_date").take(10).ifBlank { LocalDate.now().toString() },
            address = u.str("address"),
            rights = mergeUserRights(modules, existing),
        )
        success = null
        error = null
    }

    fun saveForm() {
        val f = form ?: return
        if (f.name.isBlank() || f.email.isBlank() || f.branchId <= 0) {
            error = "Name, login email and branch are required."
            return
        }
        if (f.id == null && f.password.length < 8) {
            error = "Password must be at least 8 characters for new users."
            return
        }
        scope.launch {
            saving = true
            error = null
            success = null
            try {
                val body = JsonObject().apply {
                    f.id?.let { addProperty("id", it) }
                    addProperty("name", f.name.trim())
                    addProperty("email", f.email.trim().lowercase())
                    if (f.phone.isNotBlank()) addProperty("phone", f.phone.trim())
                    if (f.regNo.isNotBlank()) addProperty("reg_no", f.regNo.trim())
                    addProperty("staff_role", f.staffRole)
                    addProperty("branch_id", f.branchId)
                    if (f.password.isNotBlank()) addProperty("password", f.password)
                    if (f.joiningDate.isNotBlank()) addProperty("joining_date", f.joiningDate)
                    if (f.address.isNotBlank()) addProperty("address", f.address.trim())
                    add("rights", rightsToJsonArray(f.rights))
                }
                runCatching { withContext(Dispatchers.IO) { rightsRepo.saveUser(body) } }
                    .onSuccess {
                        form = null
                        success = "User saved successfully."
                        reload()
                    }
                    .onFailure { error = careErr(it, "Save failed.") }
            } finally {
                saving = false
            }
        }
    }

    Column(Modifier.fillMaxSize()) {
        error?.let { ErrorBanner(it) { error = null } }
        success?.let {
            Surface(color = Color(0xFFD1FAE5), shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth().padding(bottom = 6.dp)) {
                Text(it, Modifier.padding(10.dp), style = MaterialTheme.typography.labelMedium, color = Color(0xFF166534))
            }
        }

        if (form != null) {
            StaffFormSheet(
                form = form!!,
                branches = branches,
                saving = saving,
                onChange = { form = it },
                onCancel = { form = null },
                onSave = { saveForm() },
            )
            return
        }

        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            SalesDenseSearchField(
                value = search,
                onValueChange = { search = it },
                placeholder = "Search user…",
                modifier = Modifier.weight(1f),
                leadingIcon = { Icon(Icons.Default.Search, null, Modifier.size(16.dp), tint = PharmaTeal) },
            )
            PharmaPrimaryButton("Create", onClick = { openCreate() })
            IconButton(onClick = { reload() }) {
                Icon(Icons.Default.Refresh, "Refresh", tint = PharmaTeal)
            }
        }
        Text(
            "Create staff logins and assign View / Edit / Delete per module",
            style = MaterialTheme.typography.labelSmall,
            color = PharmaMuted,
            modifier = Modifier.padding(vertical = 6.dp),
        )

        when {
            loading && users.isEmpty() -> LoadingBlock("Loading users…")
            filtered.isEmpty() -> PharmaCard {
                Text(
                    if (search.isNotBlank()) "No users match your search." else "No staff users yet. Tap Create to add one.",
                    Modifier.padding(16.dp),
                    style = MaterialTheme.typography.bodySmall,
                    color = PharmaMuted,
                )
            }
            else -> LazyColumn(
                Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(6.dp),
                contentPadding = PaddingValues(bottom = 16.dp),
            ) {
                items(filtered, key = { it.jInt("id") }) { u ->
                    PharmaCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(u.str("name"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                                Text(u.str("email"), style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
                                Text(
                                    listOfNotNull(
                                        roleLabel(u.str("role")),
                                        u.str("phone").takeIf { it.isNotBlank() },
                                        u.str("reg_no").takeIf { it.isNotBlank() }?.let { "Reg $it" },
                                    ).joinToString(" · "),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaMuted,
                                )
                                val n = u.arr("rights").count { el ->
                                    if (!el.isJsonObject) return@count false
                                    val o = el.asJsonObject
                                    o.bool("can_view") || o.bool("can_edit") || o.bool("can_delete")
                                }
                                Text(
                                    if (n > 0) "$n module(s)" else "No rights",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = PharmaTeal,
                                )
                            }
                            IconButton(onClick = { openEdit(u) }) {
                                Icon(Icons.Default.Edit, "Edit", tint = PharmaTeal)
                            }
                            IconButton(onClick = { confirmDelete = u }) {
                                Icon(Icons.Default.Delete, "Deactivate", tint = MaterialTheme.colorScheme.error)
                            }
                        }
                    }
                }
            }
        }
    }

    confirmDelete?.let { u ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            title = { Text("Deactivate user?", style = MaterialTheme.typography.titleSmall) },
            text = { Text("Deactivate “${u.str("name")}”? They will no longer be able to sign in.") },
            confirmButton = {
                TextButton(onClick = {
                    val id = u.jInt("id")
                    confirmDelete = null
                    scope.launch {
                        runCatching { withContext(Dispatchers.IO) { rightsRepo.deleteUser(id) } }
                            .onSuccess {
                                success = "User deactivated."
                                reload()
                            }
                            .onFailure { error = careErr(it, "Delete failed.") }
                    }
                }) { Text("Deactivate", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = null }) { Text("Cancel") } },
        )
    }
}

@Composable
private fun StaffFormSheet(
    form: StaffForm,
    branches: List<JsonObject>,
    saving: Boolean,
    onChange: (StaffForm) -> Unit,
    onCancel: () -> Unit,
    onSave: () -> Unit,
) {
    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState()),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                if (form.id != null) "Edit User" else "Create User",
                Modifier.weight(1f),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                color = PharmaSlate,
            )
            PharmaOutlinedButton("Close", onClick = onCancel)
        }
        PharmaCard {
            SalesFormField("Staff Name *", form.name, { onChange(form.copy(name = it)) }, Modifier.fillMaxWidth())
            Spacer(Modifier.height(6.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                SalesFormField("Registration No.", form.regNo, { onChange(form.copy(regNo = it)) }, Modifier.weight(1f))
                SalesFormField("Contact No.", form.phone, { onChange(form.copy(phone = it.filter { c -> c.isDigit() || c == '+' }.take(15))) }, Modifier.weight(1f))
            }
            Spacer(Modifier.height(6.dp))
            SalesFormField("Login Email *", form.email, { onChange(form.copy(email = it)) }, Modifier.fillMaxWidth())
            Spacer(Modifier.height(6.dp))
            SalesFormField(
                if (form.id == null) "Password *" else "Password",
                form.password,
                { onChange(form.copy(password = it)) },
                Modifier.fillMaxWidth(),
                placeholder = if (form.id != null) "Leave blank to keep current" else "Min 8 characters",
            )
            Spacer(Modifier.height(6.dp))
            var roleOpen by remember { mutableStateOf(false) }
            var branchOpen by remember { mutableStateOf(false) }
            val branchLabel = branches.firstOrNull { it.jInt("id") == form.branchId }
                ?.let { "${it.str("code")} — ${it.str("name")}" }
                ?: "Select branch"
            Box(Modifier.fillMaxWidth()) {
                PPickField("Role", roleLabel(form.staffRole), "Role", { roleOpen = true }, Modifier.fillMaxWidth())
                DropdownMenu(expanded = roleOpen, onDismissRequest = { roleOpen = false }) {
                    listOf("pharmacist", "purchase", "cashier", "delivery").forEach { r ->
                        DropdownMenuItem(
                            text = { Text(roleLabel(r)) },
                            onClick = { onChange(form.copy(staffRole = r)); roleOpen = false },
                        )
                    }
                }
            }
            Spacer(Modifier.height(6.dp))
            Box(Modifier.fillMaxWidth()) {
                PPickField("Branch *", branchLabel, "Select branch", { branchOpen = true }, Modifier.fillMaxWidth())
                DropdownMenu(expanded = branchOpen, onDismissRequest = { branchOpen = false }) {
                    branches.forEach { b ->
                        DropdownMenuItem(
                            text = { Text("${b.str("code")} — ${b.str("name")}") },
                            onClick = { onChange(form.copy(branchId = b.jInt("id"))); branchOpen = false },
                        )
                    }
                }
            }
            Spacer(Modifier.height(6.dp))
            PDateField("Joining Date", form.joiningDate, { onChange(form.copy(joiningDate = it)) }, Modifier.fillMaxWidth())
            Spacer(Modifier.height(6.dp))
            SalesFormField("Address", form.address, { onChange(form.copy(address = it)) }, Modifier.fillMaxWidth())
        }

        PharmaCard {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("Module Rights", Modifier.weight(1f), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
            }
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                PharmaOutlinedButton("All View") {
                    onChange(form.copy(rights = form.rights.map { it.copy(canView = true) }))
                }
                PharmaOutlinedButton("All Edit") {
                    onChange(form.copy(rights = form.rights.map { it.copy(canView = true, canEdit = true) }))
                }
                PharmaOutlinedButton("All Delete") {
                    onChange(form.copy(rights = form.rights.map { it.copy(canView = true, canDelete = true) }))
                }
                PharmaOutlinedButton("Clear All") {
                    onChange(form.copy(rights = form.rights.map { it.copy(canView = false, canEdit = false, canDelete = false) }))
                }
            }
            Spacer(Modifier.height(6.dp))
            form.rights.forEachIndexed { idx, row ->
                Column(
                    Modifier
                        .fillMaxWidth()
                        .padding(vertical = 4.dp)
                        .border(1.dp, PharmaBorder, RoundedCornerShape(6.dp))
                        .padding(8.dp),
                ) {
                    Text(row.moduleName, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                    if (row.moduleCode == "RETURN_NOTE") {
                        Text(
                            "Includes Debit Note, Credit Note, and Retail Sales Return",
                            style = MaterialTheme.typography.labelSmall,
                            color = PharmaMuted,
                        )
                    }
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        RightCheck("View", row.canView) { checked ->
                            val next = form.rights.toMutableList()
                            next[idx] = row.copy(
                                canView = checked,
                                canEdit = if (!checked) false else row.canEdit,
                                canDelete = if (!checked) false else row.canDelete,
                            )
                            onChange(form.copy(rights = next))
                        }
                        RightCheck("Edit", row.canEdit) { checked ->
                            val next = form.rights.toMutableList()
                            next[idx] = row.copy(canEdit = checked, canView = if (checked) true else row.canView)
                            onChange(form.copy(rights = next))
                        }
                        RightCheck("Delete", row.canDelete) { checked ->
                            val next = form.rights.toMutableList()
                            next[idx] = row.copy(canDelete = checked, canView = if (checked) true else row.canView)
                            onChange(form.copy(rights = next))
                        }
                    }
                }
            }
        }

        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PharmaOutlinedButton("Cancel", Modifier.weight(1f), onClick = onCancel)
            PharmaPrimaryButton(if (saving) "Saving…" else "Save User", Modifier.weight(1f), enabled = !saving, onClick = onSave)
        }
        Spacer(Modifier.height(16.dp))
    }
}

@Composable
private fun RightCheck(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(
        Modifier.padding(end = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Checkbox(checked = checked, onCheckedChange = onChange, colors = CheckboxDefaults.colors(checkedColor = PharmaTeal))
        Text(label, style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
    }
}

@Composable
private fun HideCatalogCheck(
    title: String,
    subtitle: String,
    checked: Boolean,
    onChange: (Boolean) -> Unit,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .clickable { onChange(!checked) }
            .padding(vertical = 4.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Checkbox(
            checked = checked,
            onCheckedChange = onChange,
            colors = CheckboxDefaults.colors(checkedColor = PharmaTeal),
        )
        Column(Modifier.weight(1f).padding(top = 10.dp)) {
            Text(title, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            Text(subtitle, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        }
    }
}
