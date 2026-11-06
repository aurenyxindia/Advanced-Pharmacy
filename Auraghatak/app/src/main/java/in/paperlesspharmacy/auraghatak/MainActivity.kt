package in.paperlesspharmacy.auraghatak

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import in.paperlesspharmacy.auraghatak.navigation.Routes
import in.paperlesspharmacy.auraghatak.shop.ShopViewModel
import in.paperlesspharmacy.auraghatak.ui.screens.ConnectScreen
import in.paperlesspharmacy.auraghatak.ui.screens.ExploreScreen
import in.paperlesspharmacy.auraghatak.ui.screens.HomeScreen
import in.paperlesspharmacy.auraghatak.ui.screens.OrdersScreen
import in.paperlesspharmacy.auraghatak.ui.screens.ShopScreen
import in.paperlesspharmacy.auraghatak.ui.theme.AuraGharTakTheme
import in.paperlesspharmacy.auraghatak.util.TokenParser

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        val deepLinkToken = intent?.let { parseIntentToken(it) }

        setContent {
            AuraGharTakTheme {
                val nav = rememberNavController()
                val vm: ShopViewModel = viewModel()
                val pharmacies by vm.savedPharmacies.collectAsStateWithLifecycle()
                val orders by vm.localOrders.collectAsStateWithLifecycle()
                val ui by vm.ui.collectAsStateWithLifecycle()

                LaunchedEffect(deepLinkToken) {
                    deepLinkToken?.let { token ->
                        vm.connectToken(token)
                        nav.navigate(Routes.Shop) {
                            popUpTo(Routes.Home)
                        }
                    }
                }

                NavHost(navController = nav, startDestination = Routes.Home) {
                    composable(Routes.Home) {
                        HomeScreen(
                            pharmacies = pharmacies,
                            onConnect = { nav.navigate(Routes.Connect) },
                            onOpenPharmacy = { token ->
                                vm.connectToken(token)
                                nav.navigate(Routes.Shop)
                            },
                            onExplore = { nav.navigate(Routes.Explore) },
                            onOrders = { nav.navigate(Routes.Orders) },
                        )
                    }
                    composable(Routes.Connect) {
                        ConnectScreen(
                            onBack = { nav.popBackStack() },
                            onConnected = { token ->
                                vm.connectToken(token)
                                nav.navigate(Routes.Shop) {
                                    popUpTo(Routes.Home)
                                }
                            },
                        )
                    }
                    composable(Routes.Shop) {
                        val rxPicker = rememberLauncherForActivityResult(
                            ActivityResultContracts.OpenDocument(),
                        ) { uri: Uri? ->
                            uri?.let { vm.setPrescriptionFromUri(it) }
                        }
                        ShopScreen(
                            vm = vm,
                            onBack = { nav.popBackStack() },
                            onPickPrescription = { rxPicker.launch(arrayOf("image/*", "application/pdf")) },
                        )
                    }
                    composable(Routes.Orders) {
                        OrdersScreen(orders = orders, onBack = { nav.popBackStack() })
                    }
                    composable(Routes.Explore) {
                        ExploreScreen(
                            branches = ui.branches,
                            loading = ui.branchesLoading,
                            onBack = { nav.popBackStack() },
                            onRefresh = { vm.loadBranches() },
                        )
                    }
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        if (parseIntentToken(intent) != null) {
            recreate()
        }
    }

    private fun parseIntentToken(intent: Intent): String? {
        val data = intent.data ?: return null
        return TokenParser.parse(data.toString())
            ?: data.getQueryParameter("token")
    }
}
