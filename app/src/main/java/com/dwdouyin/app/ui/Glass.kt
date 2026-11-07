// 抖音作品解析下载工具 - dwdouyin（仅用于个人技术学习，禁止商用、批量爬取，遵守版权法规）
package com.dwdouyin.app.ui

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.ReadOnlyComposable
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * 设计系统 v4：
 *   表面之间只用色调台阶分层，不用 1dp 描边、不用阴影、不用渐变；
 *   一套配色 = 地面 + 三档表面 + 一个强调色，强调色只标记主操作与选中态；
 *   配色可在设置里切换，默认「墨白」（黑白两档）。
 *
 * 字阶（依据 UI 基础规范：标题行高 1.3 倍，正文行高 1.5 倍）：
 *   大标题 28/36 Bold · 标题 20/26 Bold · 分块 17/22 SemiBold
 *   正文 14/21 Regular · 标签 13/19 Medium · 说明 12/18 Regular
 */

// ============ 配色 ============

/** 一套配色：地面、三档表面、强调色及其按下态、文字三档。 */
data class Palette(
    val key: String,
    val name: String,
    val pageBg: Color,
    val surface1: Color,
    val surface2: Color,
    val surface3: Color,
    val accent: Color,
    val accentPressed: Color,
    val onAccent: Color,
    val textDark: Color,
    val textGray: Color,
    val textLight: Color,
    val swatch: List<Color>,
)

/** 墨白：默认，黑白两档主次。 */
val PaletteMono = Palette(
    key = "mono",
    name = "墨白",
    pageBg = Color(0xFFF4F3F0),
    surface1 = Color(0xFFFEFDFC),
    surface2 = Color(0xFFEDEBE6),
    surface3 = Color(0xFFE2DFD8),
    accent = Color(0xFF14140F),
    accentPressed = Color(0xFF2B2B24),
    onAccent = Color(0xFFFFFFFF),
    textDark = Color(0xFF14140F),
    textGray = Color(0xFF69675F),
    textLight = Color(0xFF9E9C93),
    swatch = listOf(Color(0xFF14140F), Color(0xFF69675F), Color(0xFFE2DFD8)),
)

/** 紫霞：E6A1FF / AC88FF / 7445E0 */
val PaletteViolet = Palette(
    key = "violet",
    name = "紫霞",
    pageBg = Color(0xFFF8F3FF),
    surface1 = Color(0xFFFFFFFF),
    surface2 = Color(0xFFEFE6FD),
    surface3 = Color(0xFFE2D3FA),
    accent = Color(0xFF7445E0),
    accentPressed = Color(0xFF5A31B8),
    onAccent = Color(0xFFFFFFFF),
    textDark = Color(0xFF1E1430),
    textGray = Color(0xFF6B5F80),
    textLight = Color(0xFF9E93B0),
    swatch = listOf(Color(0xFFE6A1FF), Color(0xFFAC88FF), Color(0xFF7445E0)),
)

/** 玫红：FFADD1 / F276B6 / F44579 */
val PaletteRose = Palette(
    key = "rose",
    name = "玫红",
    pageBg = Color(0xFFFFF4F8),
    surface1 = Color(0xFFFFFFFF),
    surface2 = Color(0xFFFFE6EF),
    surface3 = Color(0xFFFFD2E1),
    accent = Color(0xFFF44579),
    accentPressed = Color(0xFFC92F5E),
    onAccent = Color(0xFFFFFFFF),
    textDark = Color(0xFF2A121C),
    textGray = Color(0xFF7D5B67),
    textLight = Color(0xFFAE8E9A),
    swatch = listOf(Color(0xFFFFADD1), Color(0xFFF276B6), Color(0xFFF44579)),
)

/** 青瓷：76ED90 / 22D9AE / 05A882 */
val PaletteJade = Palette(
    key = "jade",
    name = "青瓷",
    pageBg = Color(0xFFF1FBF5),
    surface1 = Color(0xFFFFFFFF),
    surface2 = Color(0xFFDDF7E8),
    surface3 = Color(0xFFC7F0DA),
    accent = Color(0xFF05A882),
    accentPressed = Color(0xFF04866A),
    onAccent = Color(0xFFFFFFFF),
    textDark = Color(0xFF0E231C),
    textGray = Color(0xFF4F6F64),
    textLight = Color(0xFF87A79C),
    swatch = listOf(Color(0xFF76ED90), Color(0xFF22D9AE), Color(0xFF05A882)),
)

/** 赤金：FFD36A / FF8D52 / EE2C2C */
val PaletteEmber = Palette(
    key = "ember",
    name = "赤金",
    pageBg = Color(0xFFFFF7F0),
    surface1 = Color(0xFFFFFFFF),
    surface2 = Color(0xFFFFE9D8),
    surface3 = Color(0xFFFFD8BE),
    accent = Color(0xFFEE2C2C),
    accentPressed = Color(0xFFC41F1F),
    onAccent = Color(0xFFFFFFFF),
    textDark = Color(0xFF2B1512),
    textGray = Color(0xFF7C5B4C),
    textLight = Color(0xFFAF8E7E),
    swatch = listOf(Color(0xFFFFD36A), Color(0xFFFF8D52), Color(0xFFEE2C2C)),
)

/** 蓝海：67D6CA / 3283FF / 002CB0 */
val PaletteAzure = Palette(
    key = "azure",
    name = "蓝海",
    pageBg = Color(0xFFF2F7FF),
    surface1 = Color(0xFFFFFFFF),
    surface2 = Color(0xFFDEEAFE),
    surface3 = Color(0xFFC8DBFD),
    accent = Color(0xFF002CB0),
    accentPressed = Color(0xFF00228C),
    onAccent = Color(0xFFFFFFFF),
    textDark = Color(0xFF0C1A33),
    textGray = Color(0xFF506176),
    textLight = Color(0xFF8B9AAD),
    swatch = listOf(Color(0xFF67D6CA), Color(0xFF3283FF), Color(0xFF002CB0)),
)

/** 全部可选配色，第一项为默认。 */
val AllPalettes: List<Palette> = listOf(
    PaletteMono, PaletteViolet, PaletteRose, PaletteJade, PaletteEmber, PaletteAzure,
)

fun paletteOf(key: String?): Palette = AllPalettes.firstOrNull { it.key == key } ?: PaletteMono

val LocalPalette = compositionLocalOf { PaletteMono }

/** 主题提供者：把当前配色注入组合树。 */
@Composable
fun DTheme(palette: Palette, content: @Composable () -> Unit) {
    CompositionLocalProvider(LocalPalette provides palette, content = content)
}

/** 当前配色。 */
val palette: Palette
    @Composable @ReadOnlyComposable get() = LocalPalette.current

// ============ 色彩令牌（由当前配色派发） ============

val PageBg: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.pageBg
val Surface1: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface1
val Surface2: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface2
val Surface3: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface3

/** 唯一强调色：主操作实心块、选中态反色。 */
val BluePrimary: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.accent
val BlueDeep: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.accentPressed
val OnAccent: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.onAccent

/** 兼容旧引用：原来的"浅蓝底"就是第二档表面。 */
val BlueLightBg: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface2
val BlueSoftBg: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface2
val HeroTop: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface1
val HeroBottom: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface1
val GreenSave: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.accent

val TextDark: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.textDark
val TextGray: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.textGray
val TextLightGray: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.textLight

val CardWhite: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface1
val CardBorder: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface3
val ThumbBg: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface3
val DividerGray: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface3

// 兼容旧引用的别名
val BgDeep: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.pageBg
val GlassCard: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface1
val GlassCardHover: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface2
val GlassStroke: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.surface3
val Teal: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.accent
val SkyBlue: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.accent
val TextPrimary: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.textDark
val TextSecondary: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.textGray
val TextDim: Color
    @Composable @ReadOnlyComposable get() = LocalPalette.current.textGray

// 语义色：跨配色保持稳定，只按配色做轻微调和
val Success: Color
    @Composable @ReadOnlyComposable get() = Color(0xFF2F6B4F)
val Danger: Color
    @Composable @ReadOnlyComposable get() = Color(0xFFB03A32)
val DangerBg: Color
    @Composable @ReadOnlyComposable get() = Color(0xFFF8EDEB)
val WarnAmber: Color
    @Composable @ReadOnlyComposable get() = Color(0xFF8A6A22)

val primaryGradient: Brush
    @Composable @ReadOnlyComposable get() = Brush.linearGradient(listOf(BluePrimary, BluePrimary))
val heroGradient: Brush
    @Composable @ReadOnlyComposable get() = Brush.linearGradient(listOf(Surface1, Surface1))
val bgGradient: Brush
    @Composable @ReadOnlyComposable get() = Brush.linearGradient(listOf(PageBg, PageBg))

/** 照片上压字的深色蒙版（唯一允许的渐变：只压在图片上，与配色无关）。 */
val PhotoScrim = Brush.verticalGradient(
    listOf(Color(0x00000000), Color(0x33000000), Color(0xC4000000)),
)

// ============ 字阶（标题 1.3×，正文 1.5×） ============

val TypeDisplay = TextStyle(
    fontSize = 28.sp,
    lineHeight = 36.sp,
    fontWeight = FontWeight.Bold,
    letterSpacing = (-0.6).sp,
)
val TypeTitle = TextStyle(
    fontSize = 20.sp,
    lineHeight = 26.sp,
    fontWeight = FontWeight.Bold,
    letterSpacing = (-0.3).sp,
)
val TypeSection = TextStyle(
    fontSize = 17.sp,
    lineHeight = 22.sp,
    fontWeight = FontWeight.SemiBold,
)
val TypeBody = TextStyle(
    fontSize = 14.sp,
    lineHeight = 21.sp,
    fontWeight = FontWeight.Normal,
)
val TypeLabel = TextStyle(
    fontSize = 13.sp,
    lineHeight = 19.sp,
    fontWeight = FontWeight.Medium,
)
val TypeCaption = TextStyle(
    fontSize = 12.sp,
    lineHeight = 18.sp,
    fontWeight = FontWeight.Normal,
)

// ============ 组件 ============

/** 第一档表面块：无描边、无阴影，只靠比地面亮一档分层。 */
@Composable
fun GlassCard(
    modifier: Modifier = Modifier,
    hover: Boolean = false,
    cornerRadius: Int = 16,
    content: @Composable () -> Unit,
) {
    val shape = RoundedCornerShape(cornerRadius.dp)
    Box(
        modifier
            .clip(shape)
            .background(if (hover) Surface2 else Surface1, shape),
    ) {
        content()
    }
}

/** 主操作：强调色实心块 + onAccent 字，按压回落一档。 */
@Composable
fun GradientButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    fontSize: TextUnit = 15.sp,
) {
    val shape = RoundedCornerShape(14.dp)
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(
        targetValue = if (pressed && enabled) 0.985f else 1f,
        animationSpec = tween(150),
        label = "btnScale",
    )
    Box(
        modifier
            .scale(scale)
            .clip(shape)
            .background(
                if (enabled) {
                    if (pressed) BlueDeep else BluePrimary
                } else {
                    BluePrimary.copy(alpha = 0.28f)
                },
                shape,
            )
            .clickable(interactionSource = interaction, indication = null, enabled = enabled) { onClick() }
            .padding(horizontal = 20.dp, vertical = 15.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            text,
            color = if (enabled) OnAccent else OnAccent.copy(alpha = 0.75f),
            fontWeight = FontWeight.SemiBold,
            fontSize = fontSize,
        )
    }
}

/** 次操作：第二档表面块，不描边。 */
@Composable
fun GlassOutlineButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    contentColor: Color = TextDark,
) {
    val shape = RoundedCornerShape(14.dp)
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(
        targetValue = if (pressed && enabled) 0.985f else 1f,
        animationSpec = tween(150),
        label = "outlineScale",
    )
    Box(
        modifier
            .scale(scale)
            .clip(shape)
            .background(if (pressed && enabled) Surface3 else Surface2, shape)
            .clickable(interactionSource = interaction, indication = null, enabled = enabled) { onClick() }
            .padding(horizontal = 18.dp, vertical = 13.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = if (enabled) contentColor else TextLightGray, fontSize = 14.sp)
    }
}

/** 画质胶囊：选中 = 强调色反色，未选中 = 第二档表面。 */
@Composable
fun QualityChip(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val shape = RoundedCornerShape(12.dp)
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(
        targetValue = if (pressed) 0.985f else 1f,
        animationSpec = tween(150),
        label = "chipScale",
    )
    Box(
        modifier
            .scale(scale)
            .clip(shape)
            .background(if (selected) BluePrimary else Surface2, shape)
            .clickable(interactionSource = interaction, indication = null) { onClick() }
            .padding(horizontal = 14.dp, vertical = 10.dp),
    ) {
        Text(
            label,
            fontSize = 13.sp,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
            color = if (selected) OnAccent else TextDark,
        )
    }
}

/** 小标签：第二档表面块 + 主文字色。 */
@Composable
fun Badge(text: String, color: Color = TextDark) {
    Box(
        Modifier
            .clip(RoundedCornerShape(6.dp))
            .background(Surface2)
            .padding(horizontal = 7.dp, vertical = 3.dp),
    ) {
        Text(text, fontSize = 11.sp, color = color, fontWeight = FontWeight.Medium)
    }
}

/** 功能说明条目：无卡片、无描边，靠左侧强调色短竖条做锚点。 */
@Composable
fun FeatureItem(
    emoji: String,
    title: String,
    desc: String,
) {
    Row(
        Modifier
            .padding(horizontal = 20.dp)
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .padding(vertical = 13.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Box(
            Modifier
                .padding(top = 3.dp)
                .width(3.dp)
                .height(30.dp)
                .background(BluePrimary),
        )
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(
                title,
                fontSize = 16.sp,
                fontWeight = FontWeight.SemiBold,
                color = TextDark,
                maxLines = 1,
            )
            Spacer(Modifier.height(3.dp))
            Text(
                desc,
                style = TypeCaption,
                color = TextGray,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
        Text(emoji, fontSize = 15.sp, color = TextLightGray)
    }
}

/** 分块标题：20/26 Bold，负字距。 */
@Composable
fun SectionTitle(text: String, modifier: Modifier = Modifier) {
    Text(
        text = text,
        modifier = modifier.padding(horizontal = 20.dp),
        style = TypeTitle,
        color = TextDark,
    )
}
