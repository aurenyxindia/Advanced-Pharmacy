package com.paperlesspharmacy.auraghartak.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Star
import androidx.compose.material.icons.outlined.StarOutline
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.paperlesspharmacy.auraghartak.data.CatalogItem
import kotlinx.coroutines.delay

@OptIn(ExperimentalFoundationApi::class)
@Composable
fun CatalogScreen(vm: AuraViewModel, onBack: () -> Unit, onCheckout: () -> Unit) {
    val branch = vm.branch
    var showSubs by remember { mutableStateOf(false) }
    if (showSubs || vm.substitutesLoading || vm.substitutesForName.isNotBlank()) {
        SubstitutesDialog(vm) { showSubs = false }
    }
    LaunchedEffect(vm.catalogQuery, branch?.id) {
        delay(if (vm.catalogQuery.isBlank()) 0 else 350)
        vm.loadCatalog()
    }
    Column(Modifier.fillMaxSize().background(Page)) {
        Column(
            Modifier
                .fillMaxWidth()
                .background(Teal)
                .statusBarsPadding(),
        ) {
            AppTopBar(
                title = branch?.title ?: "Pharmacy",
                subtitle = branch?.subtitle?.takeIf { it.isNotBlank() },
                onBack = onBack,
            )
            Box(Modifier.padding(start = AuraDimens.screenPad, end = AuraDimens.screenPad, bottom = 10.dp)) {
                SearchField(vm.catalogQuery, "Search medicine", onChange = { vm.catalogQuery = it })
            }
        }

        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                vm.catalogLoading && vm.catalog.isEmpty() -> LoadingState("Loading medicines…")
                vm.catalogError != null && vm.catalog.isEmpty() -> Column(Modifier.padding(AuraDimens.spaceMd)) {
                    ErrorBanner(vm.catalogError ?: "", onRetry = vm::loadCatalog)
                }
                vm.catalog.isEmpty() -> Column(Modifier.padding(AuraDimens.spaceMd)) {
                    EmptyHint(
                        "Nothing in stock for that search",
                        "Try another name, or get notified when it is back.",
                    )
                    if (vm.catalogQuery.trim().length >= 2 && branch?.source != "retail") {
                        Spacer(Modifier.height(AuraDimens.spaceMd))
                        SecondaryButton("Notify when back in stock") { vm.addStockAlertForQuery() }
                    }
                }
                else -> BoxWithConstraints(Modifier.fillMaxSize()) {
                    val cols = when {
                        maxWidth < 400.dp -> 2
                        maxWidth < 600.dp -> 3
                        else -> 4
                    }
                    LazyVerticalGrid(
                        columns = GridCells.Fixed(cols),
                        contentPadding = PaddingValues(horizontal = AuraDimens.screenPad, vertical = 10.dp),
                        horizontalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm),
                        verticalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm),
                    ) {
                        items(vm.catalog, key = { it.bindingId }) { item ->
                            MedicineTile(
                                item,
                                vm.qtyOf(item.bindingId),
                                favorite = vm.isFavorite(item),
                                showAlternatives = branch?.source != "retail",
                                onToggleFavorite = { vm.toggleFavorite(item) },
                                onLongPress = {
                                    if (branch?.source != "retail") {
                                        showSubs = true
                                        vm.loadSubstitutes(item.bindingId, item.productName)
                                    }
                                },
                                onMinus = { vm.changeQty(item, -1) },
                                onPlus = { vm.changeQty(item, 1) },
                            )
                        }
                    }
                }
            }
        }

        if (vm.cart.isNotEmpty()) {
            Column(Modifier.navigationBarsPadding()) {
                CartSummaryBar(
                    itemCount = vm.cart.sumOf { it.qty },
                    totalLabel = inr(vm.cartTotal()),
                    actionLabel = "View cart",
                    onAction = onCheckout,
                )
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MedicineTile(
    item: CatalogItem,
    qty: Int,
    favorite: Boolean,
    showAlternatives: Boolean,
    onToggleFavorite: () -> Unit,
    onLongPress: () -> Unit,
    onMinus: () -> Unit,
    onPlus: () -> Unit,
) {
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
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                Icon(
                    if (favorite) Icons.Outlined.Star else Icons.Outlined.StarOutline,
                    contentDescription = "Favourite",
                    tint = if (favorite) Teal else Mute,
                    modifier = Modifier.clickable(onClick = onToggleFavorite).padding(2.dp),
                )
            }
            Text(
                item.productName,
                fontWeight = FontWeight.SemiBold,
                color = Ink,
                fontSize = 12.sp,
                lineHeight = 15.sp,
                textAlign = TextAlign.Center,
                minLines = 2,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            if (item.detail.isNotBlank()) {
                Text(
                    item.detail,
                    style = MaterialTheme.typography.bodySmall,
                    textAlign = TextAlign.Center,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(top = 4.dp),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                StockQtyChip(item.availableQty)
                if (item.scheduleCategory.isNotBlank()) {
                    Spacer(Modifier.padding(horizontal = 4.dp))
                    ScheduleChip(item.scheduleCategory)
                }
            }
            if (showAlternatives) {
                Text(
                    "Alternatives",
                    color = Teal,
                    fontSize = 10.sp,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier
                        .padding(top = 2.dp)
                        .clickable(onClick = onLongPress),
                )
            }
            Spacer(Modifier.height(6.dp))
            val (priceText, packText) = unitPriceLabel(item.mrp, item.unitsPerPack, item.packSize, item.productName)
            Text(
                priceText,
                fontWeight = FontWeight.Bold,
                color = TealDeep,
                fontSize = 13.sp,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.padding(top = 4.dp),
            )
            if (packText != null) {
                Text(
                    packText,
                    color = Mute,
                    fontSize = 10.sp,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            Spacer(Modifier.height(AuraDimens.spaceSm))
            if (qty == 0) {
                Surface(
                    onClick = onPlus,
                    shape = RoundedCornerShape(AuraDimens.radiusSm),
                    color = Teal,
                    modifier = Modifier.fillMaxWidth(),
                ) {
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
