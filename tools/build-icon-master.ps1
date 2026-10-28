<#
.SYNOPSIS
	Draws every raster icon from the geometry of assets/icon.svg.

.DESCRIPTION
	The mark is five primitives — a rounded card, a back sheet, a mask, a front
	sheet and two rules — so it is drawn here with GDI+ instead of being
	downscaled from a browser screenshot: the result is deterministic at every
	size, with no device-pixel-ratio surprises.

	assets/icon-master.png is a 1536 px render (3× the largest icon). Each size
	below is drawn at its exact pixel size, never resampled, so hairlines stay
	crisp.

	Keep the numbers in sync with assets/icon.svg — they are the same drawing.

.EXAMPLE
	pwsh -File tools/build-icon-master.ps1
#>

[CmdletBinding()]
param(
	[string] $Root = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$card = [System.Drawing.Color]::FromArgb( 255, 0xF7, 0xF8, 0xFA )
$ink  = [System.Drawing.Color]::FromArgb( 255, 0x0B, 0x0D, 0x10 )

function New-Icon( [int] $size ) {
	$s = $size / 512.0

	$bmp = New-Object System.Drawing.Bitmap( $size, $size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb )
	$g   = [System.Drawing.Graphics]::FromImage( $bmp )

	$g.SmoothingMode       = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
	$g.PixelOffsetMode     = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
	$g.CompositingQuality  = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
	$g.Clear( [System.Drawing.Color]::Transparent )

	# Rounded card, radius 76/512.
	$radius = 76.0 * $s
	$card_path = New-Object System.Drawing.Drawing2D.GraphicsPath
	$d = $radius * 2
	$card_path.AddArc( 0, 0, $d, $d, 180, 90 )
	$card_path.AddArc( $size - $d, 0, $d, $d, 270, 90 )
	$card_path.AddArc( $size - $d, $size - $d, $d, $d, 0, 90 )
	$card_path.AddArc( 0, $size - $d, $d, $d, 90, 90 )
	$card_path.CloseFigure()
	$card_brush = New-Object System.Drawing.SolidBrush( $card )
	$g.FillPath( $card_brush, $card_path )

	$pen = New-Object System.Drawing.Pen( $ink, ( 30.0 * $s ) )
	$pen.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Miter

	# Back sheet. Coordinates are computed first: inside a comma list the comma
	# binds tighter than *, so "196 * $s, 98 * $s" would build an array.
	$back_x = 196 * $s
	$back_y = 98 * $s
	$back_w = 218 * $s
	$back_h = 218 * $s
	$g.DrawRectangle( $pen, $back_x, $back_y, $back_w, $back_h )

	# Card-coloured mask over the back sheet, then the front sheet on top.
	$g.FillRectangle( $card_brush, ( 83 * $s ), ( 181 * $s ), ( 248 * $s ), ( 248 * $s ) )
	$g.DrawRectangle( $pen, ( 98 * $s ), ( 196 * $s ), ( 218 * $s ), ( 218 * $s ) )

	# Two rules: the content that travels with the copy.
	$ink_brush = New-Object System.Drawing.SolidBrush( $ink )
	$g.FillRectangle( $ink_brush, ( 145 * $s ), ( 265 * $s ), ( 124 * $s ), ( 28 * $s ) )
	$g.FillRectangle( $ink_brush, ( 145 * $s ), ( 317 * $s ), ( 124 * $s ), ( 28 * $s ) )

	$ink_brush.Dispose()
	$pen.Dispose()
	$card_brush.Dispose()
	$card_path.Dispose()
	$g.Dispose()

	return $bmp
}

$master = New-Icon 1536
$master.Save( (Join-Path $Root 'assets/icon-master.png'), [System.Drawing.Imaging.ImageFormat]::Png )
$master.Dispose()
Write-Host '  assets/icon-master.png (1536)'

foreach ( $size in 512, 256, 128, 64 ) {
	$bitmap = New-Icon $size
	$bitmap.Save( (Join-Path $Root "assets/icon-$size.png"), [System.Drawing.Imaging.ImageFormat]::Png )
	$bitmap.Dispose()
	Write-Host "  assets/icon-$size.png"
}

Write-Host 'Done.'
