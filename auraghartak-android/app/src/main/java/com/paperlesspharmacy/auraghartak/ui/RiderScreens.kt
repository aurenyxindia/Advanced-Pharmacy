package com.paperlesspharmacy.auraghartak.ui

import android.Manifest
import android.app.Application
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Logout
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.paperlesspharmacy.auraghartak.data.ApiException
import com.paperlesspharmacy.auraghartak.data.DeliveryApi
import com.paperlesspharmacy.auraghartak.data.RiderOrder
import com.paperlesspharmacy.auraghartak.data.RiderPrefs
import com.paperlesspharmacy.auraghartak.data.SessionExpiredException
import com.paperlesspharmacy.auraghartak.notify.OrderStatusSync
import com.paperlesspharmacy.auraghartak.notify.RiderLocationService
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class RiderViewModel(app: Application) : AndroidViewModel(app) {
    private val api = DeliveryApi(app)
    private val prefs = RiderPrefs(app)

    var loginId by mutableStateOf("")
    var password by mutableStateOf("")
    var loading by mutableStateOf(false)
        private set
    var error by mutableStateOf<String?>(null)
    var info by mutableStateOf<String?>(null)
    var orders by mutableStateOf<List<RiderOrder>>(emptyList())
        private set
    var busyOrderId by mutableStateOf<Long?>(null)
        private set
    var riderName by mutableStateOf(prefs.session()?.name.orEmpty())
        private set
    var sessionExpired by mutableStateOf(false)
        private set

    val isLoggedIn: Boolean get() = prefs.session() != null

    fun login(onSuccess: () -> Unit) {
        if (loading) return
        if (loginId.isBlank() || password.isBlank()) {
            error = "Enter your staff email or mobile and password."
            return
        }
        loading = true
        error = null
        viewModelScope.launch {
            try {
                val s = withContext(Dispatchers.IO) { api.login(loginId, password) }
                riderName = s.name
                password = ""
                sessionExpired = false
                onSuccess()
            } catch (e: ApiException) {
                error = e.message
            } finally {
                loading = false
            }
        }
    }

    fun refresh(silent: Boolean = false) {
        if (!silent) loading = true
        viewModelScope.launch {
            try {
                orders = withContext(Dispatchers.IO) { api.riderOrders() }
                if (!silent) error = null
            } catch (_: SessionExpiredException) {
                sessionExpired = true
            } catch (e: ApiException) {
                if (!silent) error = e.message
            } finally {
                loading = false
            }
        }
    }

    fun start(order: RiderOrder, onStarted: () -> Unit) = act(order) {
        api.start(order.orderId).also { onStarted() }
    }

    fun delivered(order: RiderOrder) = act(order) { api.delivered(order.orderId) }

    private fun act(order: RiderOrder, call: suspend () -> String) {
        if (busyOrderId != null) return
        busyOrderId = order.orderId
        error = null
        viewModelScope.launch {
            try {
                info = withContext(Dispatchers.IO) { call() }.ifBlank { null }
                orders = withContext(Dispatchers.IO) { api.riderOrders() }
            } catch (_: SessionExpiredException) {
                sessionExpired = true
            } catch (e: ApiException) {
                error = e.message
            } finally {
                busyOrderId = null
            }
        }
    }

    fun logout(onDone: () -> Unit) {
        RiderLocationService.stop(getApplication())
        viewModelScope.launch {
            withContext(Dispatchers.IO) { api.logout() }
            orders = emptyList()
            riderName = ""
            onDone()
        }
    }
}

@Composable
fun RiderLoginScreen(vm: RiderViewModel, onBack: () -> Unit, onSuccess: () -> Unit) {
    Box(
        Modifier
            .fillMaxSize()
            .background(AuthBackgroundBrush)
            .statusBarsPadding(),
    ) {
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .imePadding()
                .navigationBarsPadding()
                .padding(vertical = AuraDimens.spaceLg),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            BrandMark(large = true)
            Spacer(Modifier.height(AuraDimens.spaceLg))
            AuthFormColumn {
                Text(
                    "Delivery staff",
                    style = MaterialTheme.typography.headlineMedium,
                    color = Ink,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
                Text(
                    "Use the login your pharmacy created for you in Advanced Pharmacy",
                    style = MaterialTheme.typography.bodyMedium,
                    color = Mute,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(top = AuraDimens.spaceXs),
                )
                Spacer(Modifier.height(AuraDimens.spaceMd))
                SoftCard(elevated = true) {
                    FormField("Email or mobile number", vm.loginId) { vm.loginId = it.trim() }
                    PasswordField("Password", vm.password) { vm.password = it }
                }
                vm.error?.let {
                    Spacer(Modifier.height(AuraDimens.spaceMd))
                    ErrorBanner(it)
                }
                Spacer(Modifier.height(AuraDimens.spaceMd))
                PrimaryButton("Log in as delivery staff", loading = vm.loading) { vm.login(onSuccess) }
                TextButton(onClick = onBack, modifier = Modifier.fillMaxWidth()) {
                    Text("Back to customer login", color = TealDeep, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
fun RiderHomeScreen(vm: RiderViewModel, onLoggedOut: () -> Unit) {
    val context = LocalContext.current
    var pendingStart by remember { mutableStateOf<RiderOrder?>(null) }

    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        val order = pendingStart
        pendingStart = null
        if (order == null) {
            if (RiderLocationService.hasLocationPermission(context)) {
                RiderLocationService.start(context)
                vm.refresh(silent = true)
            } else {
                vm.error = "Location permission is needed so the customer can track the delivery."
            }
            return@rememberLauncherForActivityResult
        }
        if (RiderLocationService.hasLocationPermission(context)) {
            vm.start(order) { RiderLocationService.start(context) }
        } else {
            vm.error = "Location permission is needed so the customer can track the delivery."
        }
    }

    fun requestStart(order: RiderOrder) {
        if (RiderLocationService.hasLocationPermission(context)) {
            vm.start(order) { RiderLocationService.start(context) }
            return
        }
        pendingStart = order
        val perms = buildList {
            add(Manifest.permission.ACCESS_FINE_LOCATION)
            add(Manifest.permission.ACCESS_COARSE_LOCATION)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
        }
        permissionLauncher.launch(perms.toTypedArray())
    }

    LaunchedEffect(Unit) {
        vm.refresh()
        while (true) {
            delay(30_000)
            vm.refresh(silent = true)
        }
    }
    LaunchedEffect(vm.orders) {
        val active = vm.orders.any { it.trackingActive }
        if (active && RiderLocationService.hasLocationPermission(context)) {
            RiderLocationService.start(context)
        } else if (!active && vm.orders.isNotEmpty()) {
            RiderLocationService.stop(context)
        }
    }
    LaunchedEffect(vm.sessionExpired) {
        if (vm.sessionExpired) {
            RiderLocationService.stop(context)
            onLoggedOut()
        }
    }

    Column(Modifier.fillMaxSize().background(ScreenBackgroundBrush).statusBarsPadding()) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = AuraDimens.screenPad, vertical = AuraDimens.spaceSm),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text("My deliveries", style = MaterialTheme.typography.headlineMedium)
                Text(
                    vm.riderName.ifBlank { "Delivery staff" },
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            IconButton(onClick = { vm.refresh() }) {
                Icon(Icons.Outlined.Refresh, contentDescription = "Refresh", tint = TealDeep)
            }
            IconButton(onClick = { vm.logout(onLoggedOut) }) {
                Icon(Icons.AutoMirrored.Outlined.Logout, contentDescription = "Log out", tint = Mute)
            }
        }

        vm.error?.let {
            Box(Modifier.padding(horizontal = AuraDimens.screenPad)) { ErrorBanner(it) { vm.refresh() } }
            Spacer(Modifier.height(AuraDimens.spaceSm))
        }
        val needsLocation = vm.orders.any { it.trackingActive } && !RiderLocationService.hasLocationPermission(context)
        if (needsLocation) {
            Box(Modifier.padding(horizontal = AuraDimens.screenPad, vertical = AuraDimens.spaceXs)) {
                SoftCard {
                    Text("Live location is off", style = MaterialTheme.typography.titleSmall, color = Danger)
                    Text(
                        "You have an order out for delivery. Allow location so the customer can see you on the map.",
                        style = MaterialTheme.typography.bodySmall,
                    )
                    Spacer(Modifier.height(AuraDimens.spaceSm))
                    PrimaryButton("Share live location") {
                        pendingStart = null
                        val perms = buildList {
                            add(Manifest.permission.ACCESS_FINE_LOCATION)
                            add(Manifest.permission.ACCESS_COARSE_LOCATION)
                            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
                        }
                        permissionLauncher.launch(perms.toTypedArray())
                    }
                }
            }
        }
        vm.info?.let {
            Text(
                it,
                color = Success,
                style = MaterialTheme.typography.bodySmall,
                modifier = Modifier.padding(horizontal = AuraDimens.screenPad, vertical = AuraDimens.spaceXs),
            )
        }

        when {
            vm.loading && vm.orders.isEmpty() -> LoadingState("Loading your deliveries…")
            vm.orders.isEmpty() -> EmptyHint(
                "No deliveries assigned",
                "When the pharmacy assigns you an AuraGharTak order, it will appear here.",
            )
            else -> LazyColumn(
                contentPadding = PaddingValues(
                    start = AuraDimens.screenPad,
                    end = AuraDimens.screenPad,
                    bottom = AuraDimens.spaceLg,
                ),
                verticalArrangement = Arrangement.spacedBy(AuraDimens.spaceMd),
                modifier = Modifier.navigationBarsPadding(),
            ) {
                items(vm.orders, key = { it.orderId }) { order ->
                    RiderOrderCard(
                        order = order,
                        busy = vm.busyOrderId == order.orderId,
                        onStart = { requestStart(order) },
                        onDelivered = { vm.delivered(order) },
                        onCall = { dialNumber(context, order.patientContact) },
                        onNavigate = { navigateTo(context, order) },
                    )
                }
            }
        }
    }
}

@Composable
private fun RiderOrderCard(
    order: RiderOrder,
    busy: Boolean,
    onStart: () -> Unit,
    onDelivered: () -> Unit,
    onCall: () -> Unit,
    onNavigate: () -> Unit,
) {
    val status = OrderStatusSync.normalizeStatus(order.status)
    SoftCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(order.orderNo, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
            StatusChip(order.status)
        }
        Spacer(Modifier.height(AuraDimens.spaceSm))
        Text(order.patientName.ifBlank { "Customer" }, style = MaterialTheme.typography.bodyLarge)
        if (order.patientAddress.isNotBlank()) {
            Text(order.patientAddress, style = MaterialTheme.typography.bodySmall)
        }
        if (order.patientNote.isNotBlank()) {
            Text("Note: ${order.patientNote}", style = MaterialTheme.typography.bodySmall, color = TealDeep)
        }
        Spacer(Modifier.height(AuraDimens.spaceXs))
        Text(
            buildString {
                append("${order.itemCount} item(s)")
                if (order.amount > 0) append(" · ₹${"%.2f".format(order.amount)}")
                if (order.invoiceNo.isNotBlank()) append(" · Bill ${order.invoiceNo}")
            },
            style = MaterialTheme.typography.labelMedium,
        )
        if (order.trackingActive) {
            Text("● Sharing live location", color = Success, style = MaterialTheme.typography.labelSmall)
        }
        Spacer(Modifier.height(AuraDimens.spaceMd))
        Row(horizontalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm)) {
            if (order.patientContact.isNotBlank()) {
                SecondaryButton("Call", Modifier.weight(1f), onCall)
            }
            if (order.drop != null || order.patientAddress.isNotBlank()) {
                SecondaryButton("Navigate", Modifier.weight(1f), onNavigate)
            }
        }
        when (status) {
            "ready", "packed" -> {
                Spacer(Modifier.height(AuraDimens.spaceSm))
                PrimaryButton("Start delivery", loading = busy, onClick = onStart)
            }
            "out_for_delivery" -> {
                Spacer(Modifier.height(AuraDimens.spaceSm))
                PrimaryButton("Mark delivered", loading = busy, onClick = onDelivered)
            }
        }
    }
}

private fun dialNumber(context: Context, phone: String) {
    val digits = phone.filter { it.isDigit() || it == '+' }
    if (digits.isBlank()) return
    runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$digits"))) }
}

private fun navigateTo(context: Context, order: RiderOrder) {
    val uri = order.drop?.let { Uri.parse("geo:${it.latitude},${it.longitude}?q=${it.latitude},${it.longitude}") }
        ?: Uri.parse("geo:0,0?q=${Uri.encode(order.patientAddress)}")
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, uri)) }
}
