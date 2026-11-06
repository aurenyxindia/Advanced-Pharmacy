package `in`.paperlesspharmacy.advanced.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.PointOfSale
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import androidx.navigation.NavType
import `in`.paperlesspharmacy.advanced.data.NavModule
import `in`.paperlesspharmacy.advanced.ui.components.PharmaOutlinedButton
import `in`.paperlesspharmacy.advanced.ui.screens.*
import `in`.paperlesspharmacy.advanced.ui.theme.CompactScreenPadding
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaOnAppBar
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTealDark
import `in`.paperlesspharmacy.advanced.ui.theme.pharmaTopAppBarColors
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppShell(vm: AppViewModel, onBuyPlan: () -> Unit = {}) {
    val nav = rememberNavController()
    val drawerState = rememberDrawerState(DrawerValue.Closed)
    val scope = rememberCoroutineScope()
    val trialDays by vm.trialDaysLeft.collectAsState()
    val navItems = remember(vm.user.collectAsState().value, vm.businessType.collectAsState().value) {
        vm.visibleNav()
    }
    val backStack by nav.currentBackStackEntryAsState()
    val currentRoute = backStack?.destination?.route ?: "dashboard"
    // Import Bill keeps its own header Back — do not hide the shell bar forever if that screen jams.
    val billingFullscreen = currentRoute == "sales_new" ||
        currentRoute.startsWith("sales_edit") ||
        currentRoute == "purchase_new" ||
        currentRoute.startsWith("purchase_edit")

    ModalNavigationDrawer(
        drawerState = drawerState,
        drawerContent = {
            ModalDrawerSheet {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .background(PharmaTealDark)
                        .padding(start = CompactScreenPadding, end = 4.dp)
                        .padding(vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f).padding(end = 4.dp)) {
                        Text(
                            "Advanced Pharmacy",
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold,
                            color = PharmaOnAppBar,
                        )
                        Text(
                            "Staff ERP",
                            style = MaterialTheme.typography.labelSmall,
                            color = Color.White.copy(alpha = 0.85f),
                        )
                    }
                    IconButton(onClick = { scope.launch { drawerState.close() } }) {
                        Icon(Icons.Default.Close, contentDescription = "Close menu", tint = PharmaOnAppBar)
                    }
                }
                LazyColumn(Modifier.padding(horizontal = 8.dp).padding(top = 8.dp)) {
                    items(navItems) { item ->
                        NavigationDrawerItem(
                            label = { Text(item.label, style = MaterialTheme.typography.labelMedium) },
                            selected = currentRoute == item.route ||
                                currentRoute.startsWith("${item.route}?") ||
                                currentRoute.startsWith("${item.route}/"),
                            icon = { Icon(item.icon, contentDescription = null, modifier = Modifier.size(18.dp)) },
                            onClick = {
                                scope.launch { drawerState.close() }
                                nav.navigate(item.route) { launchSingleTop = true }
                            },
                        )
                    }
                    item {
                        Spacer(Modifier.height(12.dp))
                        PharmaOutlinedButton("Buy / renew plan", modifier = Modifier.fillMaxWidth()) {
                            scope.launch { drawerState.close() }
                            onBuyPlan()
                        }
                        Spacer(Modifier.height(8.dp))
                        PharmaOutlinedButton("Sign out", modifier = Modifier.fillMaxWidth()) {
                            vm.logout { }
                        }
                    }
                }
            }
        },
    ) {
        Scaffold(
            topBar = {
                if (!billingFullscreen) {
                    TopAppBar(
                        title = {
                            Text(
                                navItems.firstOrNull {
                                    it.route == currentRoute ||
                                        currentRoute.startsWith("${it.route}?") ||
                                        currentRoute.startsWith("${it.route}/")
                                }?.label ?: "Advanced Pharmacy",
                                style = MaterialTheme.typography.titleMedium,
                                color = PharmaOnAppBar,
                                fontWeight = FontWeight.SemiBold,
                            )
                        },
                        navigationIcon = {
                            val onDashboard = currentRoute == "dashboard"
                            if (onDashboard) {
                                IconButton(onClick = { scope.launch { drawerState.open() } }) {
                                    Icon(Icons.Default.Menu, contentDescription = "Menu", tint = PharmaOnAppBar)
                                }
                            } else {
                                // Departments: Back returns to previous screen / Dashboard.
                                IconButton(onClick = {
                                    if (!nav.popBackStack()) {
                                        nav.navigate("dashboard") {
                                            launchSingleTop = true
                                            popUpTo("dashboard") { inclusive = true }
                                        }
                                    }
                                }) {
                                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back", tint = PharmaOnAppBar)
                                }
                            }
                        },
                        actions = {
                            val onDashboard = currentRoute == "dashboard"
                            if (!onDashboard) {
                                IconButton(onClick = { scope.launch { drawerState.open() } }) {
                                    Icon(Icons.Default.Menu, contentDescription = "Menu", tint = PharmaOnAppBar)
                                }
                            }
                            val showNewSale = onDashboard &&
                                (vm.canView("SALES") || vm.hasFullAccess())
                            if (showNewSale) {
                                TextButton(onClick = { nav.navigate("sales_new") { launchSingleTop = true } }) {
                                    Icon(
                                        Icons.Default.PointOfSale,
                                        contentDescription = null,
                                        modifier = Modifier.size(16.dp),
                                        tint = PharmaOnAppBar,
                                    )
                                    Spacer(Modifier.width(4.dp))
                                    Text(
                                        "New Sale",
                                        style = MaterialTheme.typography.labelLarge,
                                        color = PharmaOnAppBar,
                                        fontWeight = FontWeight.SemiBold,
                                    )
                                }
                            }
                        },
                        colors = pharmaTopAppBarColors(),
                    )
                }
            },
        ) { padding ->
            Column(Modifier.padding(padding).fillMaxSize()) {
                val days = trialDays
                if (days != null && days >= 0) {
                    Surface(color = Color(0xFFFEF3C7), modifier = Modifier.fillMaxWidth()) {
                        Row(
                            Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(
                                if (days == 0) "Trial ends today — buy a plan to keep access."
                                else "Free trial: $days day${if (days == 1) "" else "s"} left.",
                                style = MaterialTheme.typography.labelSmall,
                                color = Color(0xFF92400E),
                                modifier = Modifier.weight(1f),
                            )
                            TextButton(onClick = onBuyPlan) {
                                Text("Buy now", style = MaterialTheme.typography.labelSmall, color = PharmaTealDark, fontWeight = FontWeight.Bold)
                            }
                        }
                    }
                }
                Box(Modifier.weight(1f).fillMaxWidth()) {
                    AppNavHost(vm, nav)
                    SupportNotifyHost(vm) { ticketId, focusReply ->
                        vm.openSupportTicket(ticketId, focusReply)
                        nav.navigate("support") { launchSingleTop = true }
                    }
                }
            }
            AgtNewOrderWatcher(
                vm,
                onDoSale = { nav.navigate("sales_new") { launchSingleTop = true } },
                onOpenOrder = { id ->
                    vm.agtOpenOrder.value = id
                    nav.navigate("agt") { launchSingleTop = true }
                },
            )
        }
    }
}

@Composable
fun AppNavHost(vm: AppViewModel, nav: NavHostController) {
    NavHost(nav, startDestination = "dashboard") {
        composable("dashboard") {
            DashboardScreen(
                vm,
                onOpenSales = { nav.navigate("sales_new") },
                onOpenReports = { nav.navigate("reports") },
                onOpenPurchaseOrder = { nav.navigate("purchase_order_auto") },
                onOpenOpeningStock = { nav.navigate("opening_stock") },
                onOpenMinInventory = { nav.navigate("opening_stock?tab=2") },
                onOpenShortStockReport = { nav.navigate("report/short-stock") },
            )
        }
        composable("masters") {
            MastersHomeScreen(vm, onBack = { nav.popBackStack() }) { key -> nav.navigate("master/$key") }
        }
        composable("master/{key}") { entry ->
            MasterCrudScreen(vm, entry.arguments?.getString("key").orEmpty()) { nav.popBackStack() }
        }
        composable("sales") {
            SalesLogScreen(
                vm,
                onNewSale = { nav.navigate("sales_new") },
                onEditBill = { id -> nav.navigate("sales_edit/$id") },
                onBack = { nav.popBackStack() },
            )
        }
        composable("sales_new") {
                 SalesBillingScreen(
                vm,
                editId = null,
                onBack = { nav.popBackStack() },
                onSaved = { nav.navigate("sales") { launchSingleTop = true } },
            )
        }
        composable(
            route = "sales_edit/{id}",
            arguments = listOf(navArgument("id") { type = NavType.IntType }),
        ) { entry ->
            val id = entry.arguments?.getInt("id") ?: 0
            SalesBillingScreen(
                vm,
                editId = id.takeIf { it > 0 },
                onBack = { nav.popBackStack() },
                onSaved = { nav.navigate("sales") { launchSingleTop = true } },
            )
        }
        composable("purchase") {
            PurchaseLogScreen(
                vm,
                onNewPurchase = { nav.navigate("purchase_new") },
                onEditPurchase = { id -> nav.navigate("purchase_edit/$id") },
                onBack = { nav.popBackStack() },
                onImport = { nav.navigate("import_bill") },
            )
        }
        composable("purchase_new") {
            PurchaseEntryScreen(
                vm,
                editId = null,
                onBack = { nav.popBackStack() },
                onSaved = {
                    if (!nav.popBackStack("purchase", inclusive = false)) {
                        nav.navigate("purchase") { launchSingleTop = true }
                    }
                },
            )
        }
        composable(
            "purchase_edit/{id}",
            arguments = listOf(navArgument("id") { type = NavType.IntType }),
        ) { entry ->
            val id = entry.arguments?.getInt("id") ?: 0
            PurchaseEntryScreen(
                vm,
                editId = id.takeIf { it > 0 },
                onBack = { nav.popBackStack() },
                onSaved = {
                    if (!nav.popBackStack("purchase", inclusive = false)) {
                        nav.navigate("purchase") { launchSingleTop = true }
                    }
                },
            )
        }
        composable("purchase_order") {
            PurchaseOrderModuleScreen(vm, onBack = { nav.popBackStack() }, openAuto = false)
        }
        composable("purchase_order_auto") {
            PurchaseOrderModuleScreen(vm, onBack = { nav.popBackStack() }, openAuto = true)
        }
        composable("import_bill") {
            ImportBillHubScreen(
                vm,
                onBack = { nav.popBackStack() },
                onSaved = {
                    if (!nav.popBackStack("purchase", false)) {
                        nav.navigate("purchase") { popUpTo("import_bill") { inclusive = true } }
                    }
                },
                onNewPurchase = { nav.navigate("purchase_new") },
            )
        }
        composable(
            route = "opening_stock?tab={tab}",
            arguments = listOf(
                navArgument("tab") {
                    type = NavType.IntType
                    defaultValue = 0
                },
            ),
        ) { entry ->
            OpeningStockModuleScreen(
                vm,
                onBack = { nav.popBackStack() },
                onOpenReport = { path -> nav.navigate("report/$path") },
                initialTab = (entry.arguments?.getInt("tab") ?: 0).coerceIn(0, 2),
            )
        }
        composable("ipd") { IpdModuleScreen(vm) { nav.popBackStack() } }
        composable("refills") { RefillModuleScreen(vm) { nav.popBackStack() } }
        composable("deliveries") { DeliveriesModuleScreen(vm) { nav.popBackStack() } }
        composable("agt") {
            AgtModuleScreen(
                vm,
                onBack = { nav.popBackStack() },
                onDoSale = { nav.navigate("sales_new") { launchSingleTop = true } },
            )
        }
        composable("mr") {
            ApiListModuleScreen("MR", "Field orders", vm, "mr/orders", listOf("order_no" to "Order", "customer_name" to "Customer", "net_amount" to "Net", "status" to "Status"), { nav.popBackStack() })
        }
        composable("payments") {
            AccountsHubScreen(vm, onBack = { nav.popBackStack() }, startOnPayments = true)
        }
        composable("return_note") {
            ReturnNoteModuleScreen(vm) { nav.popBackStack() }
        }
        composable("daily_closing") { DailyClosingScreen(vm) { nav.popBackStack() } }
        composable("reports") { ReportsScreen(vm, { nav.popBackStack() }) { path -> nav.navigate("report/$path") } }
        composable("report/{path}") { entry ->
            ReportRunnerScreen(vm, entry.arguments?.getString("path").orEmpty()) { nav.popBackStack() }
        }
        composable("master_accounts") {
            AccountsHubScreen(vm, onBack = { nav.popBackStack() }, startOnPayments = false)
        }
        composable("ho") {
            HoModuleScreen(vm) { nav.popBackStack() }
        }
        composable("profile") { ProfileScreen(vm) { nav.popBackStack() } }
        composable("support") {
            SupportModuleScreen(vm) { nav.popBackStack() }
        }
    }
}
