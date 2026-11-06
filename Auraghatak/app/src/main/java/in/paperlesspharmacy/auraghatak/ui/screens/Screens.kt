package in.paperlesspharmacy.auraghatak.ui.screens

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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.LocalPharmacy
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material.icons.filled.Store
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import in.paperlesspharmacy.auraghatak.data.api.ProductRow
import in.paperlesspharmacy.auraghatak.data.api.PublicBranch
import in.paperlesspharmacy.auraghatak.data.local.LocalOrder
import in.paperlesspharmacy.auraghatak.data.local.SavedPharmacy
import in.paperlesspharmacy.auraghatak.shop.ShopUiState
import in.paperlesspharmacy.auraghatak.shop.ShopViewModel
import in.paperlesspharmacy.auraghatak.ui.theme.Slate100
import in.paperlesspharmacy.auraghatak.ui.theme.TealDark
import in.paperlesspharmacy.auraghatak.ui.theme.TealLight
import in.paperlesspharmacy.auraghatak.ui.theme.TealPrimary
import java.text.NumberFormat
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private val inr = NumberFormat.getCurrencyInstance(Locale("en", "IN"))

@Composable
fun HomeScreen(
    pharmacies: List<SavedPharmacy>,
    onConnect: () -> Unit,
    onOpenPharmacy: (String) -> Unit,
    onExplore: () -> Unit,
    onOrders: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(Slate100)
            .padding(20.dp),
    ) {
        Spacer(modifier = Modifier.height(12.dp))
        Text("Paperless Pharmacy", style = MaterialTheme.typography.labelLarge, color = TealDark)
        Text("AuraGharTak", style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.onBackground)
        Text(
            "Order from stores near you that use our software. Open the link your pharmacy shared on WhatsApp.",
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier.padding(top = 8.dp),
        )
        Spacer(modifier = Modifier.height(24.dp))
        Button(onClick = onConnect, modifier = Modifier.fillMaxWidth()) {
            Icon(Icons.Default.Link, contentDescription = null, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text("Connect pharmacy link")
        }
        Spacer(modifier = Modifier.height(10.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            OutlinedButton(onClick = onExplore, modifier = Modifier.weight(1f)) {
                Icon(Icons.Default.Store, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text("Explore")
            }
            OutlinedButton(onClick = onOrders, modifier = Modifier.weight(1f)) {
                Icon(Icons.Default.ShoppingCart, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text("My orders")
            }
        }
        Spacer(modifier = Modifier.height(28.dp))
        Text("My pharmacies", style = MaterialTheme.typography.titleMedium)
        Spacer(modifier = Modifier.height(8.dp))
        if (pharmacies.isEmpty()) {
            Card(
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(Modifier.padding(20.dp)) {
                    Icon(Icons.Default.LocalPharmacy, contentDescription = null, tint = TealPrimary)
                    Text("No pharmacy yet", fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 8.dp))
                    Text(
                        "When your chemist sends an AuraGharTak stock link, paste it here to browse medicines and place orders.",
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
            }
        } else {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                items(pharmacies, key = { it.token }) { p ->
                    PharmacyCard(p) { onOpenPharmacy(p.token) }
                }
            }
        }
    }
}

@Composable
private fun PharmacyCard(pharmacy: SavedPharmacy, onClick: () -> Unit) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(Modifier.padding(16.dp)) {
            Text(pharmacy.name, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (pharmacy.address.isNotBlank()) {
                Text(pharmacy.address, style = MaterialTheme.typography.bodyMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            Text("Ordering as ${pharmacy.patientName}", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 4.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ConnectScreen(
    onBack: () -> Unit,
    onConnected: (String) -> Unit,
) {
    var link = remember { androidx.compose.runtime.mutableStateOf("") }
    var localError = remember { androidx.compose.runtime.mutableStateOf<String?>(null) }
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Connect pharmacy") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.surface),
            )
        },
    ) { padding ->
        Column(
            Modifier
                .padding(padding)
                .padding(20.dp)
                .fillMaxSize(),
        ) {
            Text(
                "Paste the full link from WhatsApp (or the token). Example:",
                style = MaterialTheme.typography.bodyMedium,
            )
            Text(
                "https://…/agt-shop?token=…",
                style = MaterialTheme.typography.bodyMedium,
                color = TealDark,
                modifier = Modifier.padding(vertical = 8.dp),
            )
            OutlinedTextField(
                value = link.value,
                onValueChange = { link.value = it; localError.value = null },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Shop link or token") },
                minLines = 2,
            )
            localError.value?.let {
                Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(top = 8.dp))
            }
            Spacer(Modifier.height(16.dp))
            Button(
                onClick = {
                    val token = in.paperlesspharmacy.auraghatak.util.TokenParser.parse(link.value)
                    if (token == null) {
                        localError.value = "Could not find a valid token in that text."
                    } else {
                        onConnected(token)
                    }
                },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Continue to shop")
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ShopScreen(
    vm: ShopViewModel,
    onBack: () -> Unit,
    onPickPrescription: () -> Unit,
) {
    val state by vm.ui.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    LaunchedEffect(state.error) {
        state.error?.let {
            snackbar.showSnackbar(it)
            vm.clearError()
        }
    }
    state.orderSuccess?.let { (orderNo, total) ->
        OrderSuccessView(orderNo, total, onDone = {
            vm.clearOrderSuccess()
            onBack()
        })
        return
    }
    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(state.pharmacy?.name ?: "Shop", maxLines = 1, overflow = TextOverflow.Ellipsis)
                        if (state.patientName.isNotBlank()) {
                            Text(
                                "For ${state.patientName}",
                                style = MaterialTheme.typography.bodyMedium,
                                maxLines = 1,
                            )
                        }
                    }
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    if (state.cart.isNotEmpty()) {
                        Text(
                            inr.format(vm.cartTotal()),
                            modifier = Modifier.padding(end = 12.dp),
                            fontWeight = FontWeight.SemiBold,
                            color = TealDark,
                        )
                    }
                },
            )
        },
        bottomBar = {
            if (state.cart.isNotEmpty()) {
                Button(
                    onClick = { vm.placeOrder() },
                    enabled = !state.placing,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(16.dp),
                ) {
                    if (state.placing) {
                        CircularProgressIndicator(modifier = Modifier.size(22.dp), strokeWidth = 2.dp)
                        Spacer(Modifier.width(8.dp))
                    }
                    Text("Place order · ${inr.format(vm.cartTotal())}")
                }
            }
        },
    ) { padding ->
        if (state.loading && state.products.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = TealPrimary)
            }
            return@Scaffold
        }
        LazyColumn(
            modifier = Modifier
                .padding(padding)
                .fillMaxSize()
                .background(Slate100),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item {
                state.pharmacy?.let { ph ->
                    Card(colors = CardDefaults.cardColors(containerColor = TealLight)) {
                        Column(Modifier.padding(14.dp)) {
                            Text(ph.address.orEmpty(), style = MaterialTheme.typography.bodyMedium)
                            Text("Call ${ph.contact.orEmpty()}", style = MaterialTheme.typography.bodyMedium)
                        }
                    }
                }
            }
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(
                        value = state.searchQuery,
                        onValueChange = vm::setSearchQuery,
                        modifier = Modifier.weight(1f),
                        placeholder = { Text("Search medicine…") },
                        singleLine = true,
                    )
                    IconButton(onClick = { vm.search() }) {
                        Icon(Icons.Default.Search, contentDescription = "Search")
                    }
                }
            }
            item {
                CartAndNoteSection(state, vm, onPickPrescription)
            }
            items(state.products, key = { "${it.productName}_${it.batchNo}" }) { product ->
                ProductRowCard(product, vm.qtyInCart(product), onMinus = { vm.changeQty(product, -1) }, onPlus = { vm.changeQty(product, 1) })
            }
        }
    }
}

@Composable
private fun CartAndNoteSection(state: ShopUiState, vm: ShopViewModel, onPickPrescription: () -> Unit) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp)) {
            Text("Cart", fontWeight = FontWeight.SemiBold)
            if (state.cart.isEmpty()) {
                Text("Add items from the list below.", style = MaterialTheme.typography.bodyMedium)
            } else {
                state.cart.forEach { line ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("${line.productName} × ${line.qty}", modifier = Modifier.weight(1f), maxLines = 2, overflow = TextOverflow.Ellipsis)
                        Text(inr.format(line.lineTotal))
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            OutlinedTextField(
                value = state.patientNote,
                onValueChange = vm::setPatientNote,
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Note for pharmacy") },
                minLines = 2,
            )
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilledTonalButton(onClick = onPickPrescription) { Text("Prescription photo/PDF") }
                if (state.prescriptionBase64 != null) {
                    OutlinedButton(onClick = vm::clearPrescription) { Text("Remove") }
                }
            }
            if (state.prescriptionBase64 != null) {
                Text("Prescription attached", style = MaterialTheme.typography.bodyMedium, color = TealDark)
            }
        }
    }
}

@Composable
private fun ProductRowCard(
    product: ProductRow,
    qty: Int,
    onMinus: () -> Unit,
    onPlus: () -> Unit,
) {
    val mrp = product.unitMrp ?: product.mrp ?: 0.0
    Card(modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(product.productName.orEmpty(), fontWeight = FontWeight.Medium)
                Text(product.packSize.orEmpty(), style = MaterialTheme.typography.bodyMedium)
                Text("Batch ${product.batchNo.orEmpty()} · Stock ${product.availableQty?.toInt() ?: 0}", style = MaterialTheme.typography.bodyMedium)
                Text(inr.format(mrp), fontWeight = FontWeight.SemiBold, color = TealDark)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconButton(onClick = onMinus, enabled = qty > 0) {
                    Icon(Icons.Default.Remove, contentDescription = "Less")
                }
                Text("$qty", modifier = Modifier.width(28.dp), fontWeight = FontWeight.SemiBold)
                IconButton(onClick = onPlus) {
                    Icon(Icons.Default.Add, contentDescription = "More")
                }
            }
        }
    }
}

@Composable
private fun OrderSuccessView(orderNo: String, total: Double, onDone: () -> Unit) {
    Column(
        Modifier
            .fillMaxSize()
            .background(Slate100)
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            Modifier
                .size(72.dp)
                .clip(RoundedCornerShape(20.dp))
                .background(TealLight),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Default.LocalPharmacy, contentDescription = null, tint = TealPrimary, modifier = Modifier.size(40.dp))
        }
        Spacer(Modifier.height(20.dp))
        Text("Order placed", style = MaterialTheme.typography.headlineMedium)
        Text("Order no. $orderNo", modifier = Modifier.padding(top = 8.dp))
        Text("Estimated total ${inr.format(total)}", style = MaterialTheme.typography.bodyMedium)
        Text(
            "The pharmacy will confirm and deliver your medicines.",
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier.padding(top = 12.dp),
        )
        Spacer(Modifier.height(24.dp))
        Button(onClick = onDone) { Text("Done") }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OrdersScreen(orders: List<LocalOrder>, onBack: () -> Unit) {
    val fmt = remember { SimpleDateFormat("dd-MM-yyyy HH:mm", Locale.getDefault()) }
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("My orders") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { padding ->
        if (orders.isEmpty()) {
            Box(Modifier.padding(padding).fillMaxSize(), contentAlignment = Alignment.Center) {
                Text("Orders you place in this app appear here.", style = MaterialTheme.typography.bodyMedium)
            }
        } else {
            LazyColumn(
                Modifier.padding(padding).fillMaxSize().padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(orders, key = { it.orderNo + it.placedAt }) { o ->
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp)) {
                            Text(o.orderNo, fontWeight = FontWeight.SemiBold)
                            Text(o.pharmacyName, style = MaterialTheme.typography.bodyMedium)
                            Text(inr.format(o.estimatedTotal), color = TealDark, fontWeight = FontWeight.Medium)
                            Text(fmt.format(Date(o.placedAt)), style = MaterialTheme.typography.bodyMedium)
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ExploreScreen(
    branches: List<PublicBranch>,
    loading: Boolean,
    onBack: () -> Unit,
    onRefresh: () -> Unit,
) {
    LaunchedEffect(Unit) { onRefresh() }
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Paperless pharmacies") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { padding ->
        if (loading && branches.isEmpty()) {
            Box(Modifier.padding(padding).fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
        } else {
            LazyColumn(
                Modifier.padding(padding).fillMaxSize().padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                item {
                    Text(
                        "These stores run Paperless Pharmacy. Ask your chemist for an AuraGharTak link to order from their live stock.",
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.padding(bottom = 8.dp),
                    )
                }
                items(branches, key = { it.id ?: it.hashCode() }) { b ->
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp)) {
                            Text(b.businessName ?: b.name ?: "Pharmacy", fontWeight = FontWeight.SemiBold)
                            Text(listOfNotNull(b.name, b.city).joinToString(" · "), style = MaterialTheme.typography.bodyMedium)
                        }
                    }
                }
            }
        }
    }
}
