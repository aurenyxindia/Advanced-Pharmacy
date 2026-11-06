package com.paperlesspharmacy.auraghartak.data

object BranchLocations {
    fun mergeApi(branches: List<Branch>, locations: List<StoreLocation>): List<Branch> {
        if (locations.isEmpty()) return branches
        val map = locations.associateBy { loc -> loc.source.lowercase() to loc.storeKey.trim() }
        return branches.map { branch ->
            val key = branch.source.lowercase() to branch.locationKey()
            val loc = map[key] ?: return@map branch
            branch.copy(latitude = loc.latitude, longitude = loc.longitude)
        }
    }

    fun applyCoords(branch: Branch, lat: Double, lng: Double): Branch =
        branch.copy(latitude = lat, longitude = lng)
}
