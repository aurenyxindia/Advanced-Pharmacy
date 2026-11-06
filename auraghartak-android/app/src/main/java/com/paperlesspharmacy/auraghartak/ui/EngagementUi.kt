package com.paperlesspharmacy.auraghartak.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.paperlesspharmacy.auraghartak.data.FavoriteMed
import com.paperlesspharmacy.auraghartak.data.RefillReminder
import com.paperlesspharmacy.auraghartak.data.StockAlert

@Composable
fun EngagementProfileSection(vm: AuraViewModel, onOpenCatalog: () -> Unit) {
    SoftCard {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Notifications", style = MaterialTheme.typography.titleSmall)
                Text("Order status & refill reminders on this phone", style = MaterialTheme.typography.bodySmall, color = Mute)
            }
            Switch(checked = vm.notificationsEnabled, onCheckedChange = vm::updateNotificationsEnabled)
        }
    }
    Spacer(Modifier.height(AuraDimens.spaceMd))
    SoftCard {
        Text("Favourites", style = MaterialTheme.typography.titleSmall)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        if (vm.favorites.isEmpty()) {
            Text("Star medicines in a pharmacy catalog to save them here.", style = MaterialTheme.typography.bodySmall, color = Mute)
        } else {
            vm.favorites.take(12).forEach { fav ->
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clickable {
                            vm.openFavorite(fav) { onOpenCatalog() }
                        }
                        .padding(vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(fav.productName, fontWeight = FontWeight.Medium, style = MaterialTheme.typography.bodyMedium)
                        Text(fav.branchName, style = MaterialTheme.typography.bodySmall, color = Mute)
                    }
                }
            }
        }
    }
    Spacer(Modifier.height(AuraDimens.spaceMd))
    RefillSection(vm)
    if (vm.stockAlerts.isNotEmpty()) {
        Spacer(Modifier.height(AuraDimens.spaceMd))
        SoftCard {
            Text("Stock alerts", style = MaterialTheme.typography.titleSmall)
            vm.stockAlerts.take(8).forEach { alert ->
                Text(
                    "${alert.productName} · ${alert.branchName}",
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(vertical = 4.dp),
                )
            }
        }
    }
}

@Composable
private fun RefillSection(vm: AuraViewModel) {
    var name by remember { mutableStateOf("") }
    var days by remember { mutableStateOf("30") }
    SoftCard {
        Text("Refill reminders", style = MaterialTheme.typography.titleSmall)
        Text("Chronic medicines — local reminders on this device", style = MaterialTheme.typography.bodySmall, color = Mute)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        OutlinedTextField(
            value = name,
            onValueChange = { name = it },
            label = { Text("Medicine name") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
            shape = MaterialTheme.shapes.medium,
            colors = auraTextFieldColors(),
        )
        Row(horizontalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm)) {
            OutlinedTextField(
                value = days,
                onValueChange = { days = it.filter { ch -> ch.isDigit() }.take(3) },
                label = { Text("Every (days)") },
                singleLine = true,
                modifier = Modifier.weight(1f),
                shape = MaterialTheme.shapes.medium,
                colors = auraTextFieldColors(),
            )
            PrimaryButton("Add", modifier = Modifier.weight(1f)) {
                vm.addRefill(name, days.toIntOrNull() ?: 30)
                name = ""
            }
        }
        vm.refills.forEach { refill ->
            Row(Modifier.fillMaxWidth().padding(top = AuraDimens.spaceSm), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(refill.medicineName, fontWeight = FontWeight.Medium)
                    Text("Next: ${refill.nextDue} · every ${refill.everyDays} days", style = MaterialTheme.typography.bodySmall, color = Mute)
                }
                TextButton(onClick = { vm.removeRefill(refill.id) }) { Text("Remove", color = Mute) }
            }
        }
    }
}

@Composable
fun SubstitutesDialog(vm: AuraViewModel, onDismiss: () -> Unit) {
    if (vm.substitutesForName.isBlank() && !vm.substitutesLoading) return
    AlertDialog(
        onDismissRequest = {
            vm.clearSubstitutes()
            onDismiss()
        },
        title = { Text("Alternatives (same generic)") },
        text = {
            Column {
                Text(vm.substitutesForName, fontWeight = FontWeight.SemiBold)
                Spacer(Modifier.height(AuraDimens.spaceSm))
                when {
                    vm.substitutesLoading -> CircularProgressIndicator(color = Teal, modifier = Modifier.padding(16.dp))
                    vm.substitutes.isEmpty() -> Text("No in-stock substitute at this pharmacy.", color = Mute)
                    else -> vm.substitutes.forEach { sub ->
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .clickable {
                                    vm.addSubstituteToCart(sub)
                                    onDismiss()
                                }
                                .padding(vertical = 8.dp),
                        ) {
                            Column(Modifier.weight(1f)) {
                                Text(sub.productName, fontWeight = FontWeight.Medium)
                                if (sub.genericName.isNotBlank()) {
                                    Text(sub.genericName, style = MaterialTheme.typography.bodySmall, color = Mute)
                                }
                            }
                            Text(
                                unitPriceLabel(sub.mrp, sub.unitsPerPack, sub.packSize, sub.productName).first,
                                color = TealDeep,
                                fontWeight = FontWeight.SemiBold,
                            )
                        }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = {
                vm.clearSubstitutes()
                onDismiss()
            }) { Text("Close", color = TealDeep) }
        },
    )
}

@Composable
fun PaymentMethodRow(vm: AuraViewModel) {
    Text("Payment method", style = MaterialTheme.typography.titleSmall)
    Spacer(Modifier.height(AuraDimens.spaceSm))
    Column(verticalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm)) {
        vm.paymentOptions.forEach { option ->
            val selected = vm.paymentMethod == option.id
            Surface(
                shape = MaterialTheme.shapes.medium,
                color = if (selected) MintSoft else Color.White,
                border = androidx.compose.foundation.BorderStroke(
                    1.dp,
                    if (selected) Teal else Line.copy(alpha = 0.85f),
                ),
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable { vm.paymentMethod = option.id },
            ) {
                Row(
                    Modifier.padding(horizontal = AuraDimens.spaceMd, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            option.label,
                            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Medium,
                            color = if (selected) TealDeep else MaterialTheme.colorScheme.onSurface,
                        )
                        Text(option.hint, style = MaterialTheme.typography.bodySmall, color = Mute)
                    }
                    if (selected) {
                        Icon(
                            imageVector = Icons.Outlined.CheckCircle,
                            contentDescription = null,
                            tint = Teal,
                            modifier = Modifier.size(22.dp),
                        )
                    }
                }
            }
        }
    }
}

@Composable
fun DeliverySlotRow(vm: AuraViewModel) {
    Text("Preferred delivery time", style = MaterialTheme.typography.titleSmall)
    Spacer(Modifier.height(AuraDimens.spaceSm))
    Row(
        Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState()),
        horizontalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm),
    ) {
        vm.deliverySlots.forEach { slot ->
            SelectableChip(slot, vm.deliverySlot == slot) { vm.deliverySlot = slot }
        }
    }
}

@Composable
fun PrescriptionAttachRow(label: String?, onGallery: () -> Unit, onCamera: () -> Unit, onClear: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text("Camera", color = Teal, fontWeight = FontWeight.SemiBold, modifier = Modifier.clickable(onClick = onCamera).padding(end = 12.dp))
        Text("Gallery", color = Teal, fontWeight = FontWeight.SemiBold, modifier = Modifier.clickable(onClick = onGallery))
        if (label != null) {
            Text("  ·  $label", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
            Text("Remove", color = Mute, modifier = Modifier.clickable(onClick = onClear))
        }
    }
}
