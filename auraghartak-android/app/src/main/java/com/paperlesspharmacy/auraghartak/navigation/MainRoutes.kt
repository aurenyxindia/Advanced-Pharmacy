package com.paperlesspharmacy.auraghartak.navigation

object MainRoutes {
    const val LOGIN = "login"
    const val REGISTER = "register"
    const val STORES = "stores"
    const val CATALOG = "catalog"
    const val CHECKOUT = "checkout"
    const val DONE = "done"
    /** No slashes — Navigation treats `/` as nested graphs and crashes without a parent route. */
    const val ORDERS_LIST = "orders_list"
    const val ORDERS_DETAIL = "orders_detail"
    const val PROFILE = "profile"
    const val AGT = "agt"
    const val RIDER_LOGIN = "rider_login"
    const val RIDER_HOME = "rider_home"

    fun isRiderRoute(route: String?): Boolean = route == RIDER_LOGIN || route == RIDER_HOME

    fun isMainTab(route: String?): Boolean = when (route) {
        STORES, ORDERS_LIST, ORDERS_DETAIL, PROFILE -> true
        else -> false
    }

    fun ordersTabSelected(route: String?): Boolean =
        route == ORDERS_LIST || route == ORDERS_DETAIL
}
