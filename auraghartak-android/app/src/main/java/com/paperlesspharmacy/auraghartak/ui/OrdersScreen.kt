package com.paperlesspharmacy.auraghartak.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.paperlesspharmacy.auraghartak.data.SavedOrder
import kotlinx.coroutines.delay

@Composable
fun OrdersScreen(vm: AuraViewModel, onOpen: (SavedOrder) -> Unit, onReorder: () -> Unit) {
    var reorderMsg by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { vm.refreshOrders() }
    Column(Modifier.fillMaxSize().background(Page)) {
        Column(Modifier.statusBarsPadding()) {
            ScreenBar("Your orders")
        }
        SoftCard(modifier = Modifier.padding(horizontal = AuraDimens.spaceMd, vertical = AuraDimens.spaceMd)) {
            Text("Find by mobile", style = MaterialTheme.typography.titleSmall)
            Spacer(Modifier.height(AuraDimens.spaceSm))
            OutlinedTextField(
                value = vm.lookupPhone,
                onValueChange = { vm.lookupPhone = it },
                label = { Text("Mobile number") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
                shape = MaterialTheme.shapes.medium,
                colors = auraTextFieldColors(),
            )
            Spacer(Modifier.height(AuraDimens.spaceSm))
            PrimaryButton("Find orders", loading = vm.ordersLoading) { vm.refreshOrders() }
        }
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                vm.ordersError != null && vm.orders.isEmpty() -> Column(Modifier.padding(AuraDimens.spaceMd)) {
                    ErrorBanner(vm.ordersError ?: "", onRetry = vm::refreshOrders)
                }
                vm.orders.isEmpty() && !vm.ordersLoading -> EmptyHint(
                    "No orders yet",
                    "Place an order from a pharmacy, or search with the mobile number you used.",
                )
                else -> LazyColumn(
                    contentPadding = PaddingValues(
                        start = AuraDimens.spaceMd,
                        end = AuraDimens.spaceMd,
                        bottom = AuraDimens.spaceLg,
                    ),
                    verticalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm),
                ) {
                    items(vm.orders, key = { it.orderNo + it.kind }) { order ->
                        ListCard(onClick = { onOpen(order) }) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(order.orderNo, modifier = Modifier.weight(1f), style = MaterialTheme.typography.titleSmall)
                                StatusChip(order.status)
                            }
                            Spacer(Modifier.height(4.dp))
                            Text(
                                order.pharmacyName.ifBlank { "Paperless Pharmacy" },
                                style = MaterialTheme.typography.bodyMedium,
                                color = Mute,
                            )
                            Text(prettyDate(order.createdAt), style = MaterialTheme.typography.bodySmall)
                            Spacer(Modifier.height(6.dp))
                            Text(inr(order.total), fontWeight = FontWeight.Bold, color = TealDeep, style = MaterialTheme.typography.titleSmall)
                            if (order.kind == "agt") {
                                Text("Sent from your pharmacy link", style = MaterialTheme.typography.bodySmall)
                            }
                            if (order.kind == "store" && order.orderLines().isNotEmpty()) {
                                Spacer(Modifier.height(6.dp))
                                Text(
                                    "Reorder",
                                    color = Teal,
                                    fontWeight = FontWeight.SemiBold,
                                    modifier = Modifier.clickable {
                                        reorderMsg = vm.reorderFrom(order, onReorder)
                                    },
                                )
                            }
                        }
                    }
                }
            }
        }
    }
    reorderMsg?.let { msg ->
        AlertDialog(
            onDismissRequest = { reorderMsg = null },
            title = { Text("Reorder") },
            text = { Text(msg) },
            confirmButton = { TextButton(onClick = { reorderMsg = null }) { Text("OK", color = TealDeep) } },
        )
    }
}

@Composable
fun OrderDetailScreen(vm: AuraViewModel, onBack: () -> Unit) {
    val order = vm.selectedOrder
    val detail = vm.detail
    var askCancel by remember { mutableStateOf(false) }
    val context = androidx.compose.ui.platform.LocalContext.current
    LaunchedEffect(order?.orderNo) {
        while (true) {
            delay(25_000)
            order?.let { vm.openOrder(it) }
        }
    }
    Column(Modifier.fillMaxSize().background(Page)) {
        Column(Modifier.statusBarsPadding()) {
            ScreenBar(order?.orderNo ?: "Order", onBack)
        }
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(AuraDimens.spaceMd),
        ) {
            SoftCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    StatusChip(detail?.status ?: order?.status ?: "")
                    Spacer(Modifier.weight(1f))
                    Text(
                        inr(detail?.total ?: order?.total ?: 0.0),
                        fontWeight = FontWeight.Bold,
                        style = MaterialTheme.typography.titleLarge,
                        color = TealDeep,
                    )
                }
                Spacer(Modifier.height(AuraDimens.spaceSm))
                Text(
                    detail?.pharmacyName?.ifBlank { null } ?: order?.pharmacyName ?: "Paperless Pharmacy",
                    style = MaterialTheme.typography.titleSmall,
                )
                Text(prettyDate(detail?.createdAt ?: order?.createdAt ?: ""), style = MaterialTheme.typography.bodySmall)
                val contactName = detail?.customerName?.ifBlank { null } ?: vm.profile.name
                val contactPhone = detail?.phone?.ifBlank { null } ?: order?.phone.orEmpty()
                if (contactName.isNotBlank() || contactPhone.isNotBlank()) {
                    Spacer(Modifier.height(AuraDimens.spaceSm))
                    if (contactName.isNotBlank()) {
                        Text(contactName, style = MaterialTheme.typography.bodyMedium)
                    }
                    if (contactPhone.isNotBlank()) {
                        Text(contactPhone, style = MaterialTheme.typography.bodySmall, color = Mute)
                    }
                }
                val addr = detail?.address?.trim().orEmpty()
                if (addr.isNotBlank()) {
                    Text(addr, style = MaterialTheme.typography.bodySmall, color = Mute)
                }
            }
            order?.orderNo?.takeIf { it.isNotBlank() }?.let { no ->
                LiveDeliveryCard(orderNo = no, fallbackStatus = detail?.status ?: order.status)
            }
            val lineItems = detail?.lines?.takeIf { it.isNotEmpty() } ?: order?.orderLines().orEmpty()
            if (lineItems.isNotEmpty()) {
                Spacer(Modifier.height(AuraDimens.spaceMd))
                SoftCard {
                    Text("Items", style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(AuraDimens.spaceSm))
                    lineItems.forEach { line ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                "${line.name}  × ${line.qty} @ ${inr(line.rate)}",
                                modifier = Modifier.weight(1f),
                                style = MaterialTheme.typography.bodyMedium,
                            )
                            Text(inr(line.rate * line.qty), fontWeight = FontWeight.Medium)
                        }
                    }
                }
            }
            val waPhone = vm.pharmacyWhatsAppPhone()
            if (waPhone.isNotBlank()) {
                Spacer(Modifier.height(AuraDimens.spaceMd))
                SecondaryButton("WhatsApp pharmacy") {
                    openWhatsAppChat(
                        context,
                        waPhone,
                        "Hi, about order ${order?.orderNo ?: ""} on AuraGharTak.",
                    )
                }
            }
            if (order?.kind == "agt") {
                Spacer(Modifier.height(AuraDimens.spaceMd))
                SoftCard {
                    Text("Pharmacy link order", style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(AuraDimens.spaceSm))
                    Text(
                        "This order was placed with the link your pharmacy sent you. The store sees it in AuraGharTak and will call you if anything needs to change.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = Mute,
                    )
                }
            }
            if (vm.detailLoading) {
                Spacer(Modifier.height(AuraDimens.spaceLg))
                CircularProgressIndicator(color = Teal, modifier = Modifier.size(28.dp))
            }
            if (vm.detailError != null) {
                Spacer(Modifier.height(AuraDimens.spaceMd))
                ErrorBanner(vm.detailError ?: "")
            }
            val events = detail?.events.orEmpty()
            if (events.isNotEmpty()) {
                Spacer(Modifier.height(AuraDimens.spaceLg))
                Text("Updates", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(AuraDimens.spaceMd))
                SoftCard {
                    events.forEachIndexed { index, event ->
                        Row {
                            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                                Box(Modifier.size(10.dp).clip(CircleShape).background(Teal))
                                if (index != events.lastIndex) {
                                    Box(Modifier.size(width = 2.dp, height = 40.dp).background(Mint))
                                }
                            }
                            Column(Modifier.padding(start = AuraDimens.spaceMd).padding(bottom = AuraDimens.spaceSm)) {
                                Text(statusLabel(event.status), fontWeight = FontWeight.SemiBold, color = Ink)
                                if (event.note.isNotBlank()) Text(event.note, style = MaterialTheme.typography.bodyMedium, color = Mute)
                                Text(prettyDate(event.createdAt), style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }
            }
            if (detail?.cancellable == true) {
                Spacer(Modifier.height(AuraDimens.spaceLg))
                Text(
                    if (vm.cancelling) "Cancelling…" else "Cancel order",
                    color = Danger,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier
                        .clickable(enabled = !vm.cancelling) { askCancel = true }
                        .padding(vertical = AuraDimens.spaceSm),
                )
                Text(
                    "You can cancel until the pharmacy sends it out for delivery.",
                    style = MaterialTheme.typography.bodySmall,
                )
            }
        }
    }
    if (askCancel) {
        AlertDialog(
            onDismissRequest = { askCancel = false },
            title = { Text("Cancel this order?") },
            text = { Text("The pharmacy will stop packing it and the reserved stock goes back.") },
            confirmButton = {
                TextButton(onClick = {
                    askCancel = false
                    vm.cancelSelected()
                }) { Text("Cancel order", color = Danger) }
            },
            dismissButton = {
                TextButton(onClick = { askCancel = false }) { Text("Keep it") }
            },
        )
    }
}
