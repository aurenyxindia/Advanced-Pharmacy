package `in`.paperlesspharmacy.advanced.data

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.*
import androidx.compose.ui.graphics.vector.ImageVector

data class NavModule(
    val id: String,
    val label: String,
    val route: String,
    val icon: ImageVector,
    val roles: Set<String> = APP_ROLES,
    val moduleCode: String? = null,
    val openLog: Boolean = false,
    val requireEdit: Boolean = false,
    val hideForRetail: Boolean = false,
)

val APP_ROLES = setOf("ho_admin", "branch_admin", "purchase", "pharmacist", "cashier")
val MASTERS_OPS_ROLES = setOf("ho_admin", "branch_admin", "purchase")

val MAIN_NAV: List<NavModule> = listOf(
    NavModule("dashboard", "Dashboard", "dashboard", Icons.Default.Dashboard, moduleCode = "HOME"),
    NavModule("sales", "Sales / Billing", "sales", Icons.Default.Receipt, moduleCode = "SALES", openLog = true),
    NavModule("masters", "Masters", "masters", Icons.Default.Inventory2, moduleCode = "MASTERS"),
    NavModule("purchase", "Purchase", "purchase", Icons.Default.ShoppingCart, moduleCode = "PURCHASE", openLog = true),
    NavModule("purchase_order", "Purchase Order", "purchase_order", Icons.Default.Assignment, moduleCode = "PURCHASE_ORDER"),
    NavModule("import_bill", "Import Bill", "import_bill", Icons.Default.Upload, moduleCode = "IMPORT_BILL"),
    NavModule("opening_stock", "Opening Stock", "opening_stock", Icons.Default.AddBox, moduleCode = "OPENING_STOCK"),
    NavModule("ipd", "IPD", "ipd", Icons.Default.LocalHospital, roles = setOf("ho_admin", "branch_admin", "pharmacist"), moduleCode = "IPD"),
    NavModule("refills", "Refills", "refills", Icons.Default.Notifications, roles = setOf("ho_admin", "branch_admin", "pharmacist"), moduleCode = "REFILLS"),
    NavModule("deliveries", "Deliveries", "deliveries", Icons.Default.LocalShipping, roles = setOf("ho_admin", "branch_admin", "pharmacist"), moduleCode = "DELIVERIES"),
    NavModule("agt", "AuraGharTak", "agt", Icons.Default.Home, moduleCode = "AURA_GHAR_TAK"),
    NavModule("mr", "MR", "mr", Icons.Default.Work, moduleCode = "MR", hideForRetail = true),
    NavModule("accounts", "Accounts", "master_accounts", Icons.Default.AccountBalance, roles = setOf("ho_admin", "branch_admin"), moduleCode = "MASTERS"),
    NavModule("payments", "Payments", "payments", Icons.Default.Payments, roles = setOf("ho_admin", "branch_admin"), moduleCode = "PAYMENTS"),
    NavModule("return_note", "Return Note", "return_note", Icons.Default.Undo, moduleCode = "RETURN_NOTE"),
    NavModule("daily_closing", "Daily Closing", "daily_closing", Icons.Default.EventAvailable, roles = setOf("ho_admin", "branch_admin", "pharmacist", "cashier"), moduleCode = "DAILY_CLOSING"),
    NavModule("reports", "Reports", "reports", Icons.Default.BarChart, moduleCode = "ALL_REPORTS"),
    NavModule("ho", "Head Office", "ho", Icons.Default.CorporateFare, roles = setOf("ho_admin")),
    NavModule("profile", "My Profile", "profile", Icons.Default.Person, moduleCode = "MY_PROFILE"),
    NavModule("support", "Support", "support", Icons.Default.SupportAgent),
)