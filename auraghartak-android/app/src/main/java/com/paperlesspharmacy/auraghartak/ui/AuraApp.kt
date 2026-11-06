package com.paperlesspharmacy.auraghartak.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialogDefaults
import androidx.compose.material3.Surface
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.LocalPharmacy
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.automirrored.outlined.ReceiptLong
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.activity.compose.BackHandler
import com.paperlesspharmacy.auraghartak.navigation.MainRoutes
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController

@Composable
fun AuraApp(
    startToken: String?,
    openOrdersOnLaunch: Boolean = false,
    onConsumedOpenOrders: () -> Unit = {},
) {
    val vm: AuraViewModel = viewModel()
    val auth: AuthViewModel = viewModel()
    val rider: RiderViewModel = viewModel()
    val nav = rememberNavController()
    val entry by nav.currentBackStackEntryAsState()
    val route = entry?.destination?.route
    var profileEditing by remember { mutableStateOf(false) }

    val loggedIn = auth.isLoggedIn
    val showBar = loggedIn && MainRoutes.isMainTab(route)
    val graphStart = remember {
        when {
            rider.isLoggedIn -> MainRoutes.RIDER_HOME
            loggedIn -> MainRoutes.STORES
            else -> MainRoutes.LOGIN
        }
    }

    fun navigateMainTab(tabRoute: String) {
        if (tabRoute == MainRoutes.ORDERS_LIST && route == MainRoutes.ORDERS_DETAIL) {
            nav.popBackStack(MainRoutes.ORDERS_LIST, inclusive = false)
            return
        }
        nav.navigate(tabRoute) {
            popUpTo(MainRoutes.STORES) {
                saveState = true
            }
            launchSingleTop = true
            restoreState = true
        }
    }

    BackHandler(enabled = loggedIn && route == MainRoutes.ORDERS_DETAIL) {
        nav.popBackStack()
    }

    LaunchedEffect(auth.account) {
        auth.account?.let { vm.applyUserAccount(it) }
    }
    LaunchedEffect(openOrdersOnLaunch, loggedIn) {
        if (openOrdersOnLaunch && loggedIn) {
            nav.navigate(MainRoutes.ORDERS_LIST) {
                launchSingleTop = true
            }
            onConsumedOpenOrders()
        }
    }
    LaunchedEffect(loggedIn) {
        if (loggedIn) {
            vm.loadStores()
            vm.startOrderStatusPolling()
        } else {
            vm.onLoggedOut()
        }
        if (!loggedIn && route != null && route != MainRoutes.LOGIN && route != MainRoutes.REGISTER &&
            !MainRoutes.isRiderRoute(route)
        ) {
            nav.navigate(MainRoutes.LOGIN) {
                popUpTo(nav.graph.id) { inclusive = true }
                launchSingleTop = true
            }
        }
    }
    LaunchedEffect(startToken) {
        val token = startToken?.takeIf { it.isNotBlank() } ?: return@LaunchedEffect
        vm.openAgt(token)
        nav.navigate(MainRoutes.AGT) { launchSingleTop = true }
    }

    if (vm.pendingBranch != null) {
        AlertDialog(
            onDismissRequest = { vm.pendingBranch = null },
            shape = RoundedCornerShape(AuraDimens.radiusLg),
            title = { Text("Switch pharmacy?") },
            text = { Text("Your cart is for another store. Switching clears those items.") },
            confirmButton = {
                TextButton(onClick = {
                    vm.confirmPendingBranch()
                    nav.navigate(MainRoutes.CATALOG) { launchSingleTop = true }
                }) { Text("Switch", color = TealDeep) }
            },
            dismissButton = {
                TextButton(onClick = { vm.pendingBranch = null }) { Text("Stay") }
            },
            tonalElevation = AlertDialogDefaults.TonalElevation,
        )
    }

    vm.stockLimitNotice?.let { notice ->
        AlertDialog(
            onDismissRequest = { vm.stockLimitNotice = null },
            shape = RoundedCornerShape(AuraDimens.radiusLg),
            title = { Text("Stock limit reached") },
            text = { Text(notice) },
            confirmButton = {
                TextButton(onClick = { vm.stockLimitNotice = null }) { Text("OK", color = TealDeep) }
            },
            tonalElevation = AlertDialogDefaults.TonalElevation,
        )
    }

    Scaffold(
        containerColor = Page,
        contentWindowInsets = WindowInsets(0, 0, 0, 0),
        bottomBar = {
            if (showBar) {
                Surface(color = Color.White, shadowElevation = 12.dp) {
                    Column {
                        Box(Modifier.fillMaxWidth().height(3.dp).background(TealBright))
                        NavigationBar(containerColor = Color.White, tonalElevation = 0.dp) {
                    NavigationBarItem(
                        selected = route == MainRoutes.STORES,
                        onClick = { navigateMainTab(MainRoutes.STORES) },
                        icon = { Icon(Icons.Outlined.LocalPharmacy, contentDescription = "Pharmacies") },
                        label = { Text("Pharmacies") },
                        colors = navColors(),
                    )
                    NavigationBarItem(
                        selected = MainRoutes.ordersTabSelected(route),
                        onClick = { navigateMainTab(MainRoutes.ORDERS_LIST) },
                        icon = { Icon(Icons.AutoMirrored.Outlined.ReceiptLong, contentDescription = "Orders") },
                        label = { Text("Orders") },
                        colors = navColors(),
                    )
                    NavigationBarItem(
                        selected = route == MainRoutes.PROFILE,
                        onClick = {
                            profileEditing = false
                            auth.account?.let { auth.fillForm(it) }
                            navigateMainTab(MainRoutes.PROFILE)
                        },
                        icon = { Icon(Icons.Outlined.Person, contentDescription = "Profile") },
                        label = { Text("Profile") },
                        colors = navColors(),
                    )
                        }
                    }
                }
            }
        },
    ) { padding ->
        NavHost(
            nav,
            startDestination = graphStart,
            modifier = Modifier.padding(padding),
        ) {
            composable(MainRoutes.LOGIN) {
                LoginScreen(
                    auth,
                    onRegister = { nav.navigate(MainRoutes.REGISTER) },
                    onSuccess = {
                        auth.account?.let { vm.applyUserAccount(it) }
                        nav.navigate(MainRoutes.STORES) {
                            popUpTo(MainRoutes.LOGIN) { inclusive = true }
                            launchSingleTop = true
                        }
                    },
                    onRiderLogin = {
                        rider.error = null
                        nav.navigate(MainRoutes.RIDER_LOGIN) { launchSingleTop = true }
                    },
                )
            }
            composable(MainRoutes.RIDER_LOGIN) {
                RiderLoginScreen(
                    rider,
                    onBack = {
                        if (!nav.popBackStack()) {
                            nav.navigate(MainRoutes.LOGIN) {
                                popUpTo(nav.graph.id) { inclusive = true }
                            }
                        }
                    },
                    onSuccess = {
                        nav.navigate(MainRoutes.RIDER_HOME) {
                            popUpTo(nav.graph.id) { inclusive = true }
                            launchSingleTop = true
                        }
                    },
                )
            }
            composable(MainRoutes.RIDER_HOME) {
                RiderHomeScreen(
                    rider,
                    onLoggedOut = {
                        nav.navigate(if (loggedIn) MainRoutes.STORES else MainRoutes.LOGIN) {
                            popUpTo(nav.graph.id) { inclusive = true }
                            launchSingleTop = true
                        }
                    },
                )
            }
            composable(MainRoutes.REGISTER) {
                RegisterScreen(
                    auth,
                    onLogin = { nav.popBackStack() },
                    onSuccess = {
                        auth.account?.let { vm.applyUserAccount(it) }
                        nav.navigate(MainRoutes.STORES) {
                            popUpTo(MainRoutes.LOGIN) { inclusive = true }
                            launchSingleTop = true
                        }
                    },
                )
            }
            composable(MainRoutes.STORES) {
                StoresScreen(
                    vm,
                    onOpen = { branch ->
                        if (vm.selectBranch(branch)) nav.navigate(MainRoutes.CATALOG)
                    },
                    onLink = { nav.navigate(MainRoutes.AGT) },
                )
            }
            composable(MainRoutes.CATALOG) {
                CatalogScreen(
                    vm,
                    onBack = { nav.popBackStack() },
                    onCheckout = { nav.navigate(MainRoutes.CHECKOUT) },
                )
            }
            composable(MainRoutes.CHECKOUT) {
                CheckoutScreen(
                    vm,
                    onBack = { nav.popBackStack() },
                    onPlaced = {
                        nav.navigate(MainRoutes.DONE) {
                            popUpTo(MainRoutes.CHECKOUT) { inclusive = true }
                        }
                    },
                )
            }
            composable(MainRoutes.DONE) {
                DoneScreen(
                    vm,
                    onOrders = { navigateMainTab(MainRoutes.ORDERS_LIST) },
                    onStores = {
                        nav.popBackStack(MainRoutes.STORES, inclusive = false)
                    },
                )
            }
            composable(MainRoutes.ORDERS_LIST) {
                OrdersScreen(
                    vm,
                    onOpen = { order ->
                        vm.openOrder(order)
                        nav.navigate(MainRoutes.ORDERS_DETAIL)
                    },
                    onReorder = { nav.navigate(MainRoutes.CATALOG) { launchSingleTop = true } },
                )
            }
            composable(MainRoutes.ORDERS_DETAIL) {
                if (vm.selectedOrder == null) {
                    LaunchedEffect(Unit) { nav.popBackStack() }
                } else {
                    OrderDetailScreen(vm, onBack = { nav.popBackStack() })
                }
            }
            composable(MainRoutes.AGT) {
                AgtScreen(vm, onBack = { nav.popBackStack() })
            }
            composable(MainRoutes.PROFILE) {
                ProfileScreen(
                    auth = auth,
                    vm = vm,
                    onOpenFavorite = {
                        nav.navigate(MainRoutes.CATALOG) { launchSingleTop = true }
                    },
                    editing = profileEditing,
                    onEdit = {
                        auth.account?.let { auth.fillForm(it) }
                        auth.clearError()
                        profileEditing = true
                    },
                    onCancelEdit = {
                        auth.account?.let { auth.fillForm(it) }
                        auth.editPassword = ""
                        auth.newPassword = ""
                        auth.clearError()
                        profileEditing = false
                    },
                    onSaved = {
                        auth.account?.let { vm.applyUserAccount(it) }
                        profileEditing = false
                    },
                    onLogout = {
                        auth.logout {
                            vm.onLoggedOut()
                            profileEditing = false
                            nav.navigate(MainRoutes.LOGIN) {
                                popUpTo(nav.graph.id) { inclusive = true }
                                launchSingleTop = true
                            }
                        }
                    },
                )
            }
        }
    }
}

@Composable
private fun navColors() = NavigationBarItemDefaults.colors(
    selectedIconColor = Teal,
    selectedTextColor = Teal,
    indicatorColor = Mint,
)
