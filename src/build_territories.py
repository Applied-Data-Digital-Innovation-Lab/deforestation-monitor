"""Build the working territory dataset from the raw RAISG shapefile."""

from pathlib import Path
import geopandas as gpd

ROOT = Path(__file__).resolve().parents[1]
RAW_PATH = ROOT / "data/raw/Tis_Junho2025/Tis_TerritoriosIndigenas.shp"
OUT_PATH = ROOT / "data/processed/territories.parquet"

# Scope filters.
# IN_AMAZON_BIOME: outside the biome the polygons are mostly Andean peasant communities, where forest-loss alerts carry no signal.
# LEGAL_STATUS: where recognition is pending or absent, the polygon reflects a claim rather than an enforceable boundary.
IN_AMAZON_BIOME = "s"
LEGAL_STATUS = "TI con reconocimiento oficial"

# Polygons below this are digitising slivers, not territories.
MIN_AREA_HA = 1.0

KEY = "codigo_tis"

AGG = {
    "area_sig_h": "sum",
    "nombre": "first",
    "pais": "first",
    "categoria": "first",
    "status": "first",
    "etnias": "first",
    "no_habitan": "first",
    "no_comunid": "first",
}

KEEP = [KEY] + list(AGG) + ["geometry"]


def build() -> gpd.GeoDataFrame:
    gdf = gpd.read_file(RAW_PATH)
    print(f"raw polygons: {len(gdf)}")

    gdf = gdf[
        (gdf["amzbiog"] == IN_AMAZON_BIOME)
        & (gdf["leyenda"] == LEGAL_STATUS)
    ].copy()
    print(f"after scope filters: {len(gdf)}")

    # One territory can be split across several polygons, so codigo_tis is not unique until we merge them.
    gdf = gdf.dissolve(by=KEY, aggfunc=AGG, as_index=False)
    print(f"after dissolve: {len(gdf)}")

    dropped = gdf[gdf["area_sig_h"] < MIN_AREA_HA]
    if len(dropped):
        print(f"dropping {len(dropped)} polygons below {MIN_AREA_HA} ha:")
        print(dropped[["codigo_tis", "nombre", "pais", "area_sig_h"]].to_string(index=False))

    gdf = gdf[gdf["area_sig_h"] >= MIN_AREA_HA].copy()
    print(f"after area filter: {len(gdf)}")

    # GFW expects WGS84. Source is SIRGAS 2000 (EPSG:4674).
    gdf = gdf.to_crs("EPSG:4326")

    gdf = gdf[KEEP].rename(
        columns={
            "codigo_tis": "territory_id",
            "nombre": "name",
            "pais": "country",
            "categoria": "category",
            "etnias": "ethnic_groups",
            "no_habitan": "population",
            "no_comunid": "communities",
            "area_sig_h": "area_ha",
        }
    )

    assert gdf["territory_id"].is_unique, "territory_id must be unique"
    return gdf


def main() -> None:
    gdf = build()
    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    gdf.to_parquet(OUT_PATH)
    print(f"wrote {len(gdf)} territories to {OUT_PATH}")


if __name__ == "__main__":
    main()
