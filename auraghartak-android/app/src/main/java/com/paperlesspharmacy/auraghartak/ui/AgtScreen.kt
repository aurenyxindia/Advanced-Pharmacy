package com.paperlesspharmacy.auraghartak.ui

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.ui.platform.LocalContext
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
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
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.paperlesspharmacy.auraghartak.data.AgtProduct
import kotlinx.coroutines.delay

@Composable
fun AgtScreen(vm: AuraViewModel, onBack: () -> Unit) {
    val shop = vm.agtShop
    val context = LocalContext.current
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) vm.attachPrescription(uri)
    }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicturePreview()) { bitmap ->
        if (bitmap != null) {
            val stream = java.io.ByteArrayOutputStream()
            bitmap.compress(android.graphics.Bitmap.CompressFormat.JPEG, 88, stream)
            val b64 = android.util.Base64.encodeToString(stream.toByteArray(), android.util.Base64.NO_WRAP)
            vm.attachPrescriptionFromBase64("data:image/jpeg;base64,$b64")
        }
    }
    LaunchedEffect(vm.agtQuery) {
        if (vm.agtShop == null) return@LaunchedEffect
        if (vm.agtQuery.isNotBlank()) delay(350)
        vm.loadAgt()
    }

    if (vm.agtDone != null) {
        AgtDone(vm, onBack)
        return
    }

    Column(Modifier.fillMaxSize().background(Page)) {
        Column(Modifier.statusBarsPadding()) {
            ScreenBar(shop?.pharmacyName?.ifBlank { null } ?: "Pharmacy link", onBack)
        }
        if (shop == null) {
            Column(
                Modifier
                    .verticalScroll(rememberScrollState())
                    .imePadding()
                    .navigationBarsPadding()
                    .padding(AuraDimens.spaceMd),
            ) {
                SoftCard {
                    Text("Order from your pharmacy link", style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(AuraDimens.spaceSm))
                    Text(
                        "When a Paperless Pharmacy store shares an AuraGharTak link on WhatsApp, paste it here. Your name and address stay the ones the pharmacy saved.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = Mute,
                    )
                    Spacer(Modifier.height(AuraDimens.spaceMd))
                    OutlinedTextField(
                        value = vm.agtTokenInput,
                        onValueChange = { vm.agtTokenInput = it },
                        label = { Text("Paste link or token") },
                        modifier = Modifier.fillMaxWidth(),
                        minLines = 2,
                        shape = MaterialTheme.shapes.medium,
                        colors = auraTextFieldColors(),
                    )
                }
                if (vm.agtError != null) {
                    Spacer(Modifier.height(AuraDimens.spaceMd))
                    ErrorBanner(vm.agtError ?: "")
                }
                Spacer(Modifier.height(AuraDimens.spaceLg))
                PrimaryButton("Open shop", loading = vm.agtLoading) { vm.openAgt(vm.agtTokenInput) }
            }
            return
        }

        SoftCard(modifier = Modifier.padding(horizontal = AuraDimens.spaceMd, vertical = AuraDimens.spaceMd)) {
            Text("Ordering for ${shop.patientName}", style = MaterialTheme.typography.titleSmall)
            val where = listOf(shop.patientAddress, shop.patientContact).filter { it.isNotBlank() }.joinToString(" · ")
            if (where.isNotBlank()) Text(where, style = MaterialTheme.typography.bodySmall)
            if (shop.pharmacyAddress.isNotBlank()) {
                Text(shop.pharmacyAddress, style = MaterialTheme.typography.bodySmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            if (shop.pharmacyContact.filter { it.isDigit() }.length >= 10) {
                Spacer(Modifier.height(AuraDimens.spaceSm))
                Text(
                    "WhatsApp pharmacy",
                    color = Teal,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.clickable {
                        openWhatsAppChat(context, shop.pharmacyContact, "Hi, I am ordering on AuraGharTak.")
                    },
                )
            }
            Spacer(Modifier.height(AuraDimens.spaceMd))
            SearchField(vm.agtQuery, "Search medicine", onChange = { vm.agtQuery = it })
        }

        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                vm.agtLoading && shop.products.isEmpty() -> LoadingState("Loading stock…")
                shop.products.isEmpty() -> EmptyHint("No matching stock", "Try another medicine name.")
                else -> BoxWithConstraints(Modifier.fillMaxSize()) {
                    val cols = when {
                        maxWidth < 400.dp -> 2
                        maxWidth < 600.dp -> 3
                        else -> 4
                    }
                    LazyVerticalGrid(
                        columns = GridCells.Fixed(cols),
                        contentPadding = PaddingValues(horizontal = AuraDimens.spaceMd, vertical = AuraDimens.spaceSm),
                        horizontalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm),
                        verticalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm),
                    ) {
                        items(shop.products, key = { it.name + it.batchNo }) { item ->
                            AgtTile(item, vm.agtQtyOf(item.name, item.batchNo), { vm.changeAgtQty(item, -1) }, { vm.changeAgtQty(item, 1) })
                        }
                    }
                }
            }
        }

        if (vm.agtError != null) {
            Box(Modifier.padding(horizontal = AuraDimens.spaceMd, vertical = AuraDimens.spaceXs)) {
                ErrorBanner(vm.agtError ?: "")
            }
        }

        Surface(
            modifier = Modifier.fillMaxWidth(),
            color = Color.White,
            shadowElevation = 8.dp,
        ) {
            Column(
                Modifier
                    .navigationBarsPadding()
                    .imePadding()
                    .padding(AuraDimens.spaceMd),
            ) {
                PaymentMethodRow(vm)
                Spacer(Modifier.height(AuraDimens.spaceSm))
                OutlinedTextField(
                    value = vm.agtNote,
                    onValueChange = { vm.agtNote = it },
                    label = { Text("Note for the pharmacy") },
                    modifier = Modifier.fillMaxWidth(),
                    minLines = 2,
                    shape = MaterialTheme.shapes.medium,
                    colors = auraTextFieldColors(),
                )
                Spacer(Modifier.height(AuraDimens.spaceSm))
                PrescriptionAttachRow(
                    vm.agtRxLabel,
                    onGallery = { picker.launch("image/*") },
                    onCamera = { camera.launch(null) },
                    onClear = vm::clearPrescription,
                )
                Spacer(Modifier.height(AuraDimens.spaceSm))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("${vm.agtCart.sumOf { it.qty }} items", style = MaterialTheme.typography.bodySmall)
                        Text(inr(vm.agtCartTotal()), style = MaterialTheme.typography.titleMedium)
                    }
                }
                Spacer(Modifier.height(AuraDimens.spaceSm))
                PrimaryButton("Place order", enabled = vm.agtCart.isNotEmpty(), loading = vm.agtPlacing) {
                    vm.placeAgt { }
                }
            }
        }
    }
}

@Composable
private fun AgtTile(item: AgtProduct, qty: Int, onMinus: () -> Unit, onPlus: () -> Unit) {
    val meta = listOfNotNull(
        item.packSize.ifBlank { null },
        item.expiry.takeIf { it.isNotBlank() }?.let { expiryLabel(it) },
    ).joinToString(" · ")
    Surface(
        shape = MaterialTheme.shapes.medium,
        color = if (qty > 0) MintSoft else Color.White,
        border = androidx.compose.foundation.BorderStroke(1.dp, if (qty > 0) Mint else Line.copy(alpha = 0.8f)),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(
            Modifier.padding(AuraDimens.spaceSm),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                item.name,
                fontWeight = FontWeight.SemiBold,
                color = Ink,
                fontSize = 12.sp,
                lineHeight = 15.sp,
                textAlign = TextAlign.Center,
                minLines = 2,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            if (meta.isNotBlank()) {
                Text(meta, style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center)
            }
            if (item.availableQty > 0) {
                StockQtyChip(item.availableQty, modifier = Modifier.padding(top = 6.dp))
            }
            Spacer(Modifier.height(4.dp))
            Text(
                if (item.unitMrp > 0) inr(item.unitMrp) else "Ask price",
                fontWeight = FontWeight.Bold,
                color = TealDeep,
                fontSize = 13.sp,
                maxLines = 1,
            )
            Spacer(Modifier.height(AuraDimens.spaceSm))
            if (qty == 0) {
                Surface(onClick = onPlus, shape = RoundedCornerShape(AuraDimens.radiusSm), color = Teal, modifier = Modifier.fillMaxWidth()) {
                    Text(
                        "Add",
                        color = Color.White,
                        fontWeight = FontWeight.SemiBold,
                        fontSize = 12.sp,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.padding(vertical = 8.dp),
                    )
                }
            } else {
                QtyStepper(qty, onMinus, onPlus, compact = true)
            }
        }
    }
}

@Composable
private fun AgtDone(vm: AuraViewModel, onBack: () -> Unit) {
    val done = vm.agtDone
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
        Icon(Icons.Outlined.CheckCircle, contentDescription = null, tint = Success, modifier = Modifier.size(72.dp))
        Spacer(Modifier.height(AuraDimens.spaceSm))
        Text("Order sent to the pharmacy", style = MaterialTheme.typography.headlineMedium)
        Spacer(Modifier.height(AuraDimens.spaceMd))
        SoftCard {
            Text("Order number", style = MaterialTheme.typography.labelMedium)
            Text(done?.orderNo ?: "—", style = MaterialTheme.typography.headlineMedium)
            Spacer(Modifier.height(AuraDimens.spaceSm))
            Text(inr(done?.total ?: 0.0), fontWeight = FontWeight.SemiBold, color = TealDeep, style = MaterialTheme.typography.titleLarge)
        }
        Spacer(Modifier.height(AuraDimens.spaceMd))
        Text(
            "The store will see this in AuraGharTak and prepare your medicines.",
            style = MaterialTheme.typography.bodyMedium,
            color = Mute,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.weight(1f))
        PrimaryButton("Done", onClick = {
            vm.resetAgt()
            onBack()
        })
    }
}
