package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.*
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.*
import `in`.paperlesspharmacy.advanced.ui.theme.CompactScreenPadding
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate

@Composable
fun ApiListModuleScreen(
    title: String,
    subtitle: String,
    vm: AppViewModel,
    path: String,
    columns: List<Pair<String, String>>,
    onBack: () -> Unit,
    paginated: Boolean = true,
) {
    var search by remember { mutableStateOf("") }
    var page by remember { mutableIntStateOf(1) }
    var rows by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    fun reload() {
        scope.launch {
            loading = true
            runCatching {
                withContext(Dispatchers.IO) {
                    if (paginated) vm.crud.list(path, search = search, page = page).rows
                    else vm.api.get(path, if (search.isBlank()) emptyMap() else mapOf("search" to search)).dataRows()
                }
            }.onSuccess { rows = it }.onFailure { error = it.message }
            loading = false
        }
    }
    LaunchedEffect(search, page) { reload() }

    Column(Modifier.fillMaxSize().padding(CompactScreenPadding)) {
        Row {
            PharmaOutlinedButton("Back", onClick = onBack)
            Column(Modifier.padding(start = 8.dp)) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                Text(subtitle, style = MaterialTheme.typography.bodySmall)
            }
        }
        CompactTextField(search, { search = it; page = 1 }, "Search")
        if (error != null) ErrorBanner(error!!) { error = null }
        if (loading) LoadingBlock() else {
            LazyColumn(Modifier.weight(1f)) {
                item { CompactDataRow(columns.map { it.second }) }
                items(rows) { row ->
                    CompactDataRow(columns.map { (k, _) -> row.str(k).ifBlank { "—" } })
                }
            }
            if (paginated) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    PharmaOutlinedButton("Prev", enabled = page > 1) { page -= 1 }
                    PharmaOutlinedButton("Next", enabled = rows.size >= 20) { page += 1 }
                }
            }
        }
    }
}

