package com.paperlesspharmacy.auraghartak.ui

import androidx.compose.foundation.background
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import android.net.Uri
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp

@Composable
fun CheckoutScreen(vm: AuraViewModel, onBack: () -> Unit, onPlaced: () -> Unit) {
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) vm.attachStorePrescription(uri)
    }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicturePreview()) { bitmap ->
        if (bitmap != null) {
            val stream = java.io.ByteArrayOutputStream()
            bitmap.compress(android.graphics.Bitmap.CompressFormat.JPEG, 88, stream)
            val b64 = android.util.Base64.encodeToString(stream.toByteArray(), android.util.Base64.NO_WRAP)
            vm.attachStorePrescriptionFromBase64("data:image/jpeg;base64,$b64")
        }
    }
    val scroll = rememberScrollState()
    Column(Modifier.fillMaxSize().background(Page)) {
        Column(Modifier.statusBarsPadding()) {
            ScreenBar("Delivery details", onBack)
        }
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(scroll)
                .imePadding()
                .navigationBarsPadding()
                .padding(AuraDimens.spaceMd),
        ) {
            Text(vm.branch?.title ?: "", style = MaterialTheme.typography.titleMedium)
            Text(
                "Estimated from current MRP. The pharmacy confirms the final bill.",
                style = MaterialTheme.typography.bodySmall,
            )
            Spacer(Modifier.height(AuraDimens.spaceMd))
            SoftCard {
                Text("Order summary", style = MaterialTheme.typography.titleSmall)
                Spacer(Modifier.height(AuraDimens.spaceSm))
                vm.cart.forEach { line ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            "${line.name}  × ${line.qty} @ ${inr(line.rate)}",
                            modifier = Modifier.weight(1f),
                            style = MaterialTheme.typography.bodyMedium,
                        )
                        Text(inr(line.rate * line.qty), fontWeight = FontWeight.Medium)
                    }
                }
                SectionDivider()
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("Estimated total", modifier = Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                    Text(inr(vm.cartTotal()), fontWeight = FontWeight.Bold, color = TealDeep, style = MaterialTheme.typography.titleMedium)
                }
            }
            Spacer(Modifier.height(AuraDimens.spaceLg))
            SoftCard {
                Text("Delivery contact", style = MaterialTheme.typography.titleSmall)
                Spacer(Modifier.height(AuraDimens.spaceSm))
                Field("Your name", vm.profile.name) { vm.updateProfile(vm.profile.copy(name = it)) }
                Field("Mobile number", vm.profile.phone) { vm.updateProfile(vm.profile.copy(phone = it)) }
                Field("Delivery address", vm.profile.address, singleLine = false) { vm.updateProfile(vm.profile.copy(address = it)) }
                Field("Pincode", vm.profile.pincode) { vm.updateProfile(vm.profile.copy(pincode = it)) }
                Field("Note for the pharmacy", vm.note, singleLine = false) { vm.note = it }
            }
            Spacer(Modifier.height(AuraDimens.spaceMd))
            SoftCard {
                PaymentMethodRow(vm)
            }
            Spacer(Modifier.height(AuraDimens.spaceMd))
            SoftCard {
                DeliverySlotRow(vm)
            }
            Spacer(Modifier.height(AuraDimens.spaceMd))
            SoftCard {
                Text("Prescription (if required)", style = MaterialTheme.typography.titleSmall)
                Spacer(Modifier.height(AuraDimens.spaceSm))
                PrescriptionAttachRow(
                    vm.storeRxLabel,
                    onGallery = { gallery.launch("image/*") },
                    onCamera = { camera.launch(null) },
                    onClear = vm::clearStorePrescription,
                )
            }
            if (vm.formError != null) {
                Spacer(Modifier.height(AuraDimens.spaceMd))
                ErrorBanner(vm.formError ?: "")
            }
            Spacer(Modifier.height(AuraDimens.spaceLg))
            PrimaryButton("Place order", enabled = vm.cart.isNotEmpty(), loading = vm.placing) {
                vm.placeOrder(onPlaced)
            }
            Spacer(Modifier.height(AuraDimens.spaceMd))
        }
    }
}

@Composable
fun DoneScreen(vm: AuraViewModel, onOrders: () -> Unit, onStores: () -> Unit) {
    val order = vm.lastOrder
    Column(
        Modifier
            .fillMaxSize()
            .background(Page)
            .statusBarsPadding()
            .navigationBarsPadding()
            .padding(AuraDimens.spaceLg),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.height(AuraDimens.spaceXl))
        Box(
            Modifier
                .size(88.dp)
                .clip(MaterialTheme.shapes.extraLarge)
                .background(SuccessBg),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Outlined.CheckCircle, contentDescription = null, tint = Success, modifier = Modifier.size(52.dp))
        }
        Spacer(Modifier.height(AuraDimens.spaceMd))
        Text("Order placed", style = MaterialTheme.typography.headlineMedium, textAlign = TextAlign.Center)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        Text(order?.pharmacyName ?: "Your pharmacy", style = MaterialTheme.typography.bodyMedium, color = Mute)
        Spacer(Modifier.height(AuraDimens.spaceLg))
        SoftCard {
            Text("Order number", style = MaterialTheme.typography.labelMedium)
            Text(order?.orderNo ?: "—", style = MaterialTheme.typography.headlineMedium)
            Spacer(Modifier.height(AuraDimens.spaceSm))
            Text("Estimated total", style = MaterialTheme.typography.labelMedium)
            Text(inr(order?.total ?: 0.0), fontWeight = FontWeight.SemiBold, color = TealDeep, style = MaterialTheme.typography.titleLarge)
        }
        Spacer(Modifier.height(AuraDimens.spaceMd))
        Text(
            "The pharmacy will confirm the order, pack the medicines, and update delivery status. You can follow it from Orders.",
            style = MaterialTheme.typography.bodyMedium,
            color = Mute,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.weight(1f))
        PrimaryButton("Track this order", onClick = onOrders)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        Text(
            "Back to pharmacies",
            color = Teal,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.clickable(onClick = onStores).padding(AuraDimens.spaceMd),
        )
    }
}

@Composable
private fun Field(label: String, value: String, singleLine: Boolean = true, onChange: (String) -> Unit) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        singleLine = singleLine,
        minLines = if (singleLine) 1 else 2,
        modifier = Modifier.fillMaxWidth().padding(bottom = AuraDimens.spaceSm),
        shape = MaterialTheme.shapes.medium,
        colors = auraTextFieldColors(),
    )
}
