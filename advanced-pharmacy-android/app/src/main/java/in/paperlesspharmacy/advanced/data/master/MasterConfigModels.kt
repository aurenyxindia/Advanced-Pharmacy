package `in`.paperlesspharmacy.advanced.data.master

import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName

data class MasterConfigDto(
    val key: String,
    val title: String,
    val subtitle: String,
    val icon: String? = null,
    val resourcePath: String,
    val canCreate: Boolean = true,
    val canEdit: Boolean = true,
    val canDelete: Boolean = true,
    val bindable: Boolean = false,
    val statsPath: String? = null,
    val kpiPreset: String? = null,
    val columns: List<MasterColumnDto> = emptyList(),
    val fields: List<MasterFieldDto> = emptyList(),
    val listFilters: List<MasterListFilterDto>? = null,
)

data class MasterColumnDto(
    val key: String,
    val label: String,
    val type: String? = null,
)

data class MasterFieldDto(
    val key: String,
    val label: String,
    val type: String,
    val required: Boolean = false,
    val hideOnEdit: Boolean = false,
    val options: List<MasterOptionDto>? = null,
    val optionSource: String? = null,
    val validation: String? = null,
    val maxLength: Int? = null,
    val min: Double? = null,
    val max: Double? = null,
    val showWhen: JsonElement? = null,
    val uppercase: Boolean = false,
)

data class MasterOptionDto(
    val value: JsonElement,
    val label: String,
)

data class MasterListFilterDto(
    val key: String,
    val label: String,
    val source: String,
    val showCount: Boolean = false,
)

data class GstStateDto(
    val value: String,
    val label: String,
    val code: String,
)
