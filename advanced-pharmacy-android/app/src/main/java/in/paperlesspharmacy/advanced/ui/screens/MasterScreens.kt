package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.data.master.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.CompactScreenPadding
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTeal
import `in`.paperlesspharmacy.advanced.ui.util.formatInr
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@Composable
fun MastersHomeScreen(vm: AppViewModel, onBack: () -> Unit = {}, onOpenMaster: (String) -> Unit) {
    val role = vm.user.collectAsState().value?.role
    val tiles = remember(role, vm.masterConfigs) { MasterVisibility.homeTiles(role, vm.masterConfigs) }
    var kpiLoading by remember { mutableStateOf(true) }
    var counts by remember { mutableStateOf(mapOf("products" to 0, "customers" to 0, "distributors" to 0, "doctors" to 0)) }
    val scope = rememberCoroutineScope()
    val ops = role != null && MASTERS_OPS_ROLES.contains(role)

    LaunchedEffect(role) {
        kpiLoading = true
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    val products = vm.crud.list("products", perPage = 1).total
                    val distributors = vm.crud.list("distributors", perPage = 1).total
                    val customers = if (ops) vm.crud.list("customers", perPage = 1).total else 0
                    val doctors = if (ops) vm.crud.list("doctors", perPage = 1).total else 0
                    mapOf("products" to products, "customers" to customers, "distributors" to distributors, "doctors" to doctors)
                }
            }.onSuccess { counts = it }
            kpiLoading = false
        }
    }

    LazyColumn(Modifier.fillMaxSize().padding(CompactScreenPadding), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        item {
            PoHeader(
                "Masters",
                "Shared catalog (products, generics, HSN, distributors) plus branch masters for your role.",
                onBack = onBack,
            )
        }
        item {
            LazyVerticalGrid(
                columns = GridCells.Fixed(2),
                modifier = Modifier.heightIn(max = 120.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
                userScrollEnabled = false,
            ) {
                item { KpiTile("Products", if (kpiLoading) "—" else counts["products"].toString(), PharmaTeal) }
                item { KpiTile("Customers", if (kpiLoading) "—" else counts["customers"].toString(), Color(0xFF0284C7)) }
                item { KpiTile("Distributors", if (kpiLoading) "—" else counts["distributors"].toString(), Color(0xFFD97706)) }
                item { KpiTile("Doctors", if (kpiLoading) "—" else counts["doctors"].toString(), Color(0xFF4F46E5)) }
            }
        }
        items(tiles) { tile ->
            PharmaCard(modifier = Modifier.clickable { onOpenMaster(tile.key) }) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Surface(shape = MaterialTheme.shapes.medium, color = PharmaTeal.copy(alpha = 0.12f), modifier = Modifier.size(40.dp)) {
                        Box(contentAlignment = Alignment.Center) {
                            Icon(masterIcon(tile.key), contentDescription = null, tint = PharmaTeal, modifier = Modifier.size(22.dp))
                        }
                    }
                    Column(Modifier.weight(1f)) {
                        Text(tile.title, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(tile.subtitle, style = MaterialTheme.typography.bodySmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    }
                    Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null)
                }
            }
        }
    }
}

@Composable
fun MasterCrudScreen(
    vm: AppViewModel,
    masterKey: String,
    showBack: Boolean = true,
    openCreate: Boolean = false,
    prefillName: String = "",
    onBack: () -> Unit,
) {
    val config = vm.masterConfigs[masterKey] ?: return
    val optionsSvc = remember { vm.masterOptions() }
    var search by remember { mutableStateOf("") }
    var page by remember { mutableIntStateOf(1) }
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var total by remember { mutableIntStateOf(0) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var statCards by remember { mutableStateOf<List<Pair<String, String>>>(emptyList()) }
    var scheduleCounts by remember { mutableStateOf<Map<String, Int>>(emptyMap()) }
    var categoryFilters by remember { mutableStateOf<List<Pair<String, String>>>(emptyList()) }
    var listFilterValues by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var editorOpen by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf<JsonObject?>(null) }
    var createPrefill by remember { mutableStateOf<String?>(null) }
    var autoCreateDone by remember(masterKey) { mutableStateOf(false) }
    var commonPtr by remember { mutableStateOf("") }
    var commonPts by remember { mutableStateOf("") }
    var commonId by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val perPage = 10
    val canCreate = config.canCreate && vm.canEdit("MASTERS")
    val canEdit = config.canEdit && vm.canEdit("MASTERS")
    val canDelete = config.canDelete && vm.canDelete("MASTERS")
    fun loadStats() {
        val path = config.statsPath ?: return
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) { vm.crud.fetch(path) }
            }.onSuccess { data ->
                val cards = data.arr("cards")
                statCards = (0 until cards.size()).mapNotNull { i ->
                    val el = cards.get(i)
                    if (!el.isJsonObject) return@mapNotNull null
                    val c = el.asJsonObject
                    c.str("label") to (c.str("value").ifBlank { c.jInt("value").toString() })
                }
                val sc = data.obj("schedule_counts")
                scheduleCounts = sc.entrySet().associate { (k, v) ->
                    k to (v.takeIf { it.isJsonPrimitive }?.asInt ?: 0)
                }
                val cats = data.arr("categories")
                categoryFilters = (0 until cats.size()).mapNotNull { i ->
                    val el = cats.get(i)
                    if (!el.isJsonObject) return@mapNotNull null
                    val c = el.asJsonObject
                    val name = c.str("name")
                    val value = if (name == "Uncategorised") "__UNCATEGORISED__" else name
                    value to "$name (${c.jInt("count")})"
                }
            }
        }
    }

    fun loadCommonMargin() {
        if (config.key != "rate-margins") return
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    vm.crud.list("rate-margins", search = "COMMON", perPage = 50).rows
                }
            }.onSuccess { list ->
                val row = list.firstOrNull { it.str("category").equals("COMMON", true) }
                if (row != null) {
                    commonId = row.jInt("id")
                    commonPtr = row.str("ptr_margin").ifBlank { row.amount("ptr_margin").toString() }
                    commonPts = row.str("pts_margin").ifBlank { row.amount("pts_margin").toString() }
                }
            }
        }
    }

    fun reload() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                withContext(Dispatchers.IO) {
                    val filters = listFilterValues.filterValues { it.isNotBlank() }
                    val result = vm.crud.list(config.resourcePath, search = search, page = page, perPage = perPage, filters = filters)
                    result.rows to result.total
                }
            }.onSuccess { (list, tot) ->
                rows = list
                total = tot
            }.onFailure { error = it.message }
            loading = false
        }
    }

    LaunchedEffect(masterKey) {
        optionsSvc.warmUpForConfig(config)
        loadStats()
        loadCommonMargin()
    }
    LaunchedEffect(search, page, listFilterValues) { reload() }
    LaunchedEffect(openCreate, prefillName, canCreate) {
        if (openCreate && !autoCreateDone && canCreate && config.fields.isNotEmpty()) {
            autoCreateDone = true
            editing = null
            createPrefill = prefillName.trim().takeIf { it.isNotBlank() }
            editorOpen = true
        }
    }

    Column(
        Modifier
            .fillMaxSize()
            .then(if (showBack) Modifier.padding(CompactScreenPadding) else Modifier),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            if (showBack) {
                PharmaOutlinedButton("Back", onClick = onBack)
                Spacer(Modifier.width(8.dp))
            }
            Column(Modifier.weight(1f)) {
                Text(config.title, style = MaterialTheme.typography.titleMedium)
                Text(config.subtitle, style = MaterialTheme.typography.bodySmall)
            }
        }
        if (toast != null) {
            Spacer(Modifier.height(4.dp))
            Surface(color = Color(0xFFD1FAE5), shape = MaterialTheme.shapes.small) {
                Text(toast!!, Modifier.padding(8.dp), style = MaterialTheme.typography.labelSmall)
            }
            LaunchedEffect(toast) {
                kotlinx.coroutines.delay(2500)
                toast = null
            }
        }
        if (config.key == "rate-margins") {
            Spacer(Modifier.height(6.dp))
            PharmaCard {
                Text("COMMON fixed margin", style = MaterialTheme.typography.labelMedium)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    CompactTextField(commonPtr, { commonPtr = it }, "PTR %", modifier = Modifier.weight(1f))
                    CompactTextField(commonPts, { commonPts = it }, "PTS %", modifier = Modifier.weight(1f))
                }
                PharmaPrimaryButton("Save COMMON") {
                    scope.launch {
                        val ptr = commonPtr.toDoubleOrNull()
                        val pts = commonPts.toDoubleOrNull()
                        if (ptr == null || pts == null) {
                            error = "Enter PTR and PTS between 0 and 100."
                            return@launch
                        }
                        val body = JsonObject().apply {
                            addProperty("category", "COMMON")
                            addProperty("ptr_margin", ptr)
                            addProperty("pts_margin", pts)
                            addProperty("rate_by", "Rate By Margin")
                            addProperty("is_active", 1)
                        }
                        runCatching {
                            withContext(Dispatchers.IO) {
                                if (commonId > 0) vm.crud.update("rate-margins", commonId, body)
                                else vm.crud.create("rate-margins", body)
                            }
                        }.onSuccess {
                            toast = "Common margin saved."
                            loadCommonMargin()
                            reload()
                        }.onFailure { error = it.message }
                    }
                }
            }
        }
        if (statCards.isNotEmpty()) {
            Spacer(Modifier.height(6.dp))
            LazyVerticalGrid(
                columns = GridCells.Fixed(2),
                modifier = Modifier.heightIn(max = 120.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
                userScrollEnabled = false,
            ) {
                items(statCards.size) { i ->
                    val (label, value) = statCards[i]
                    KpiTile(label, value)
                }
            }
        }
        config.listFilters?.forEach { filter ->
            Spacer(Modifier.height(4.dp))
            Text(filter.label, style = MaterialTheme.typography.labelSmall)
            LazyRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                item {
                    FilterChip(
                        selected = listFilterValues[filter.key].orEmpty().isEmpty(),
                        onClick = { listFilterValues = listFilterValues + (filter.key to ""); page = 1 },
                        label = { Text("All", style = MaterialTheme.typography.labelSmall) },
                    )
                }
                if (filter.source == "schedule") {
                    items(SCHEDULE_FILTER_BASE.size) { i ->
                        val (code, label) = SCHEDULE_FILTER_BASE[i]
                        val count = scheduleCounts[code]
                        FilterChip(
                            selected = listFilterValues[filter.key] == code,
                            onClick = { listFilterValues = listFilterValues + (filter.key to code); page = 1 },
                            label = {
                                Text(
                                    if (count != null) "$label ($count)" else label,
                                    style = MaterialTheme.typography.labelSmall,
                                )
                            },
                        )
                    }
                } else if (filter.source == "genericCategories") {
                    items(categoryFilters.size) { i ->
                        val (value, label) = categoryFilters[i]
                        FilterChip(
                            selected = listFilterValues[filter.key] == value,
                            onClick = { listFilterValues = listFilterValues + (filter.key to value); page = 1 },
                            label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                        )
                    }
                }
            }
        }
        Spacer(Modifier.height(6.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            CompactTextField(search, { search = it; page = 1 }, "Search", modifier = Modifier.weight(1f))
            if (canCreate && config.fields.isNotEmpty()) {
                PharmaPrimaryButton("Add") {
                    editing = null
                    createPrefill = null
                    editorOpen = true
                }
            }
        }
        if (error != null) {
            Spacer(Modifier.height(4.dp))
            ErrorBanner(error!!) { error = null }
        }
        Text("Total $total · page $page", style = MaterialTheme.typography.labelSmall)
        if (loading) LoadingBlock() else {
            LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(rows) { row ->
                    MasterRowCard(
                        config = config,
                        row = row,
                        canEdit = canEdit && config.fields.isNotEmpty(),
                        canDelete = canDelete && row.has("id") && config.canDelete,
                        canBind = config.bindable && vm.canEdit("MASTERS"),
                        onEdit = { editing = row; editorOpen = true },
                        onDelete = {
                            scope.launch {
                                runCatching {
                                    withContext(Dispatchers.IO) { vm.crud.delete(config.resourcePath, row.jInt("id")) }
                                }.onSuccess {
                                    toast = "Record deleted."
                                    reload()
                                    loadStats()
                                }.onFailure { error = it.message }
                            }
                        },
                        onBind = {
                            scope.launch {
                                runCatching {
                                    withContext(Dispatchers.IO) {
                                        vm.api.post(
                                            "products/bind",
                                            JsonObject().apply {
                                                addProperty("master_product_id", row.jInt("id"))
                                                addProperty("reorder_level", 0)
                                            },
                                        )
                                    }
                                }.onSuccess {
                                    toast = "\"${row.str("name")}\" added to branch catalog."
                                    loadStats()
                                }.onFailure { error = it.message }
                            }
                        },
                    )
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                PharmaOutlinedButton("Prev", enabled = page > 1) { page -= 1 }
                PharmaOutlinedButton("Next", enabled = rows.size >= perPage) { page += 1 }
            }
        }
    }

    if (editorOpen && config.fields.isNotEmpty()) {
        MasterEditorSheet(
            config = config,
            optionsSvc = optionsSvc,
            initial = editing,
            prefillName = createPrefill,
            onDismiss = {
                editorOpen = false
                createPrefill = null
            },
            onSave = { payload ->
                scope.launch {
                    runCatching {
                        withContext(Dispatchers.IO) {
                            val id = editing?.jInt("id") ?: 0
                            if (id > 0) vm.crud.update(config.resourcePath, id, payload)
                            else vm.crud.create(config.resourcePath, payload)
                        }
                    }.onSuccess {
                        editorOpen = false
                        createPrefill = null
                        toast = if (editing != null) "Record updated." else "Record created."
                        reload()
                        loadStats()
                    }.onFailure { error = it.message }
                }
            },
        )
    }
}

/** Teal “Add … in Master” row for search sheets / product hit lists. */
@Composable
fun MasterAddNewRow(
    label: String,
    modifier: Modifier = Modifier,
    onClick: () -> Unit,
) {
    Row(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .background(Color(0xFFECFDF5))
            .clickable(onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Default.Add, null, Modifier.size(16.dp), tint = PharmaTeal)
        Spacer(Modifier.width(6.dp))
        Text(label, style = MaterialTheme.typography.labelMedium, color = PharmaTeal, fontWeight = FontWeight.SemiBold)
    }
}

fun masterAddNewLabel(masterKey: String, typedName: String): String {
    val q = typedName.trim()
    return when (masterKey) {
        "distributors" -> if (q.isNotBlank()) "Add “$q” in Distributor Master" else "Add new distributor in Distributor Master"
        "products" -> if (q.isNotBlank()) "Add “$q” in Product Master" else "Add new product in Product Master"
        else -> if (q.isNotBlank()) "Add “$q”" else "Add new"
    }
}

/** Compact essential fields for inline create from billing / PO / import. */
private fun quickCreateFieldKeys(masterKey: String): Set<String>? = when (masterKey) {
    "distributors" -> setOf("name", "phone", "city", "state", "gstin", "email", "address")
    "products" -> setOf(
        "name", "pack_size", "manufacturer_id", "generic_id", "product_category",
        "schedule_category", "hsn_code", "mrp", "default_gst_rate", "dosage_form",
    )
    else -> null
}

/** Inline master create (Purchase / Import / PO) — compact form, parent stays on screen. */
@Composable
fun MasterQuickCreateSheet(
    vm: AppViewModel,
    masterKey: String,
    prefillName: String = "",
    onDismiss: () -> Unit,
    onCreated: () -> Unit,
) {
    val config = vm.masterConfigs[masterKey]
    if (config == null || config.fields.isEmpty() ||
        !config.canCreate || !(vm.canEdit("MASTERS") || vm.hasFullAccess())
    ) {
        LaunchedEffect(masterKey) { onDismiss() }
        return
    }
    val optionsSvc = remember { vm.masterOptions() }
    val scope = rememberCoroutineScope()
    var error by remember { mutableStateOf<String?>(null) }
    val title = when (masterKey) {
        "distributors" -> "Add distributor"
        "products" -> "Add product"
        else -> "New ${config.title}"
    }
    MasterEditorSheet(
        config = config,
        optionsSvc = optionsSvc,
        initial = null,
        prefillName = prefillName.trim().takeIf { it.isNotBlank() },
        bannerError = error,
        compact = true,
        sheetTitle = title,
        fieldKeyFilter = quickCreateFieldKeys(masterKey),
        onDismiss = onDismiss,
        onSave = { payload ->
            scope.launch {
                runCatching {
                    withContext(Dispatchers.IO) { vm.crud.create(config.resourcePath, payload) }
                }.onSuccess {
                    onCreated()
                }.onFailure { error = it.message ?: "Could not create record." }
            }
        },
    )
}

private val SCHEDULE_FILTER_BASE = listOf(
    "OTC" to "OTC",
    "H" to "H",
    "H1" to "H1",
    "X" to "X",
    "G" to "G",
    "RESTRICTED" to "RESTRICTED",
)

private fun masterIcon(key: String): ImageVector = when (key) {
    "products" -> Icons.Default.Medication
    "generics" -> Icons.Default.WaterDrop
    "manufacturers" -> Icons.Default.Factory
    "hsn" -> Icons.Default.Numbers
    "distributors" -> Icons.Default.LocalShipping
    "customers" -> Icons.Default.People
    "doctors" -> Icons.Default.MedicalServices
    "hospitals" -> Icons.Default.LocalHospital
    "accounts" -> Icons.Default.AccountBalance
    "rate-margins" -> Icons.Default.Percent
    "employees" -> Icons.Default.Badge
    else -> Icons.Default.Inventory2
}

@Composable
private fun MasterRowCard(
    config: MasterConfigDto,
    row: JsonObject,
    canEdit: Boolean,
    canDelete: Boolean,
    canBind: Boolean,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
    onBind: () -> Unit,
) {
    PharmaCard(modifier = Modifier.clickable(enabled = canEdit) { if (canEdit) onEdit() }) {
        config.columns.take(6).forEach { col ->
            val raw = row.str(col.key).ifBlank { "—" }
            val display = when (col.type) {
                "currency" -> formatInr(row.amount(col.key))
                else -> raw
            }
            Text("${col.label}: $display", style = MaterialTheme.typography.labelSmall, maxLines = 2)
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
            if (canBind) {
                TextButton(onClick = onBind) { Text("Bind branch", style = MaterialTheme.typography.labelSmall) }
            }
            if (canDelete) {
                TextButton(onClick = onDelete) { Text("Delete", style = MaterialTheme.typography.labelSmall) }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun MasterEditorSheet(
    config: MasterConfigDto,
    optionsSvc: MasterOptionsService,
    initial: JsonObject?,
    prefillName: String? = null,
    bannerError: String? = null,
    compact: Boolean = false,
    sheetTitle: String? = null,
    fieldKeyFilter: Set<String>? = null,
    onDismiss: () -> Unit,
    onSave: (JsonObject) -> Unit,
) {
    val isEdit = initial != null && initial.jInt("id") > 0
    val values = remember(initial, prefillName) {
        mutableStateMapOf<String, String>().apply {
            config.fields.forEach { f ->
                val raw = initial?.get(f.key)?.let { el ->
                    when {
                        el.isJsonNull -> ""
                        el.isJsonPrimitive && el.asJsonPrimitive.isNumber -> el.asNumber.toString()
                        else -> el.asString
                    }
                }.orEmpty()
                put(f.key, raw)
            }
            if (!isEdit && !prefillName.isNullOrBlank() && containsKey("name") && this["name"].isNullOrBlank()) {
                this["name"] = if (config.fields.any { it.key == "name" && it.uppercase }) {
                    prefillName.uppercase()
                } else {
                    prefillName
                }
            }
        }
    }
    var formError by remember { mutableStateOf<String?>(null) }
    var showMore by remember { mutableStateOf(false) }
    LaunchedEffect(bannerError) {
        if (!bannerError.isNullOrBlank()) formError = bannerError
    }
    var optionCache by remember { mutableStateOf<Map<String, List<MasterOption>>>(emptyMap()) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(config.key) {
        scope.launch {
            val map = mutableMapOf<String, List<MasterOption>>()
            config.fields.forEach { field ->
                val key = field.key + (field.optionSource ?: "")
                if (map.containsKey(key)) return@forEach
                map[key] = optionsSvc.optionsFor(field, config)
            }
            optionCache = map
        }
    }

    val allVisible = MasterFormLogic.visibleFields(config, values, isEdit)
    val primaryVisible = remember(allVisible, fieldKeyFilter, showMore, compact) {
        when {
            !compact || fieldKeyFilter == null || showMore -> allVisible
            else -> {
                val filtered = allVisible.filter { it.required || it.key in fieldKeyFilter }
                if (filtered.isEmpty()) allVisible else filtered
            }
        }
    }
    val hasMore = compact && fieldKeyFilter != null && allVisible.any { !it.required && it.key !in fieldKeyFilter }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = compact),
    ) {
        LazyColumn(
            Modifier.padding(horizontal = if (compact) 10.dp else 12.dp, vertical = if (compact) 6.dp else 12.dp),
            verticalArrangement = Arrangement.spacedBy(if (compact) 5.dp else 8.dp),
        ) {
            item {
                Text(
                    sheetTitle ?: if (isEdit) "Edit" else "New",
                    style = if (compact) MaterialTheme.typography.titleSmall else MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                )
            }
            if (formError != null) {
                item { ErrorBanner(formError!!) { formError = null } }
            }
            items(primaryVisible.size) { idx ->
                val field = primaryVisible[idx]
                val opts = optionCache[field.key + (field.optionSource ?: "")].orEmpty()
                when (field.type) {
                    "select", "genericSelect", "manufacturerSelect", "packSelect" -> {
                        MasterSelectField(
                            field = field,
                            value = values[field.key].orEmpty(),
                            options = opts,
                            dense = compact,
                            onChange = { v ->
                                values[field.key] = v
                                if (field.type == "manufacturerSelect") {
                                    opts.firstOrNull { it.value == v }?.extra?.let { values["manufacturer_short"] = it }
                                }
                            },
                        )
                    }
                    "textarea" -> CompactTextField(
                        values[field.key].orEmpty(),
                        { values[field.key] = it },
                        field.label + if (field.required) " *" else "",
                        singleLine = compact,
                    )
                    "password" -> CompactTextField(values[field.key].orEmpty(), { values[field.key] = it }, field.label)
                    else -> CompactTextField(
                        values[field.key].orEmpty(),
                        { values[field.key] = it },
                        field.label + if (field.required) " *" else "",
                    )
                }
            }
            if (hasMore && !showMore) {
                item {
                    TextButton(onClick = { showMore = true }) {
                        Text("More fields", style = MaterialTheme.typography.labelMedium, color = PharmaTeal)
                    }
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (compact) {
                        PharmaOutlinedButton("Cancel", Modifier.weight(1f), onClick = onDismiss)
                    }
                    PharmaPrimaryButton("Save", if (compact) Modifier.weight(1f) else Modifier.fillMaxWidth()) {
                        val stringValues = values.toMap()
                        // Validate against full visible set so required fields not in compact filter still apply.
                        allVisible.forEach { field ->
                            if (isEdit && field.type == "password" && stringValues[field.key].isNullOrBlank()) return@forEach
                            MasterFormLogic.validateField(field, stringValues[field.key].orEmpty(), true)?.let {
                                formError = it
                                if (compact && fieldKeyFilter != null && field.key !in fieldKeyFilter) showMore = true
                                return@PharmaPrimaryButton
                            }
                        }
                        onSave(MasterFormLogic.buildPayload(config, stringValues, isEdit))
                    }
                }
            }
            item { Spacer(Modifier.height(if (compact) 16.dp else 32.dp)) }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MasterSelectField(
    field: MasterFieldDto,
    value: String,
    options: List<MasterOption>,
    dense: Boolean = false,
    onChange: (String) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    val label = options.firstOrNull { it.value == value }?.label ?: value.ifBlank { "Select ${field.label}" }
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
        OutlinedTextField(
            value = label,
            onValueChange = {},
            readOnly = true,
            label = { Text(field.label + if (field.required) " *" else "", style = MaterialTheme.typography.labelSmall) },
            modifier = Modifier
                .menuAnchor()
                .fillMaxWidth()
                .then(if (dense) Modifier.heightIn(min = 48.dp) else Modifier),
            textStyle = MaterialTheme.typography.bodySmall,
            singleLine = true,
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            options.forEach { opt ->
                DropdownMenuItem(
                    text = { Text(opt.label, style = MaterialTheme.typography.bodySmall) },
                    onClick = {
                        onChange(opt.value)
                        expanded = false
                    },
                )
            }
        }
    }
}
