# 작성자: 정서진 (2. 데이터)
# 정제한 데이터로 sql/dbinsert.sql 을 만든다.
#   순서: district → calendar → station → weather → rental_daily → user_account
#
# 입력
#   - sql/district_seed.sql, sql/calendar_2024.sql   (김수연 작성, 그대로 합침)
#   - data/selected_stations.csv                     (select_stations.py 결과)
#   - data/raw/rental_daily_45.csv                   (filter_daily.py 결과)
#   - data/raw/OBS_ASOS_DD_*.csv                     (기상자료개방포털 ASOS 서울 108 일자료, 2024 전체 항목)
#   - data/user_account_insert.sql                   (왕은서 작성, 그대로 합침)
#
# 정제 규칙
#   - rental_daily : 45개 × 366일 전부 넣고, 원본에 없는 날(이용 0건)은 0으로 채움
#                    이동거리는 m 단위 반올림 (스키마 INT)
#   - weather      : 일강수량·강수 계속시간·합계 3시간 신적설·일 최심적설 빈칸 = 0
#                    평균 풍속 등 나머지 빈칸은 NULL
#
# 실행 (레포 최상위 폴더에서):  python data/make_dbinsert.py
import glob
import os
import sys

import numpy as np
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
RAW = os.path.join(HERE, 'raw')


def read_text(path):
    with open(path, encoding='utf-8') as f:
        return f.read()


def sql_str(v):
    if v is None or (isinstance(v, float) and np.isnan(v)):
        return 'NULL'
    return "'" + str(v).replace("'", "''").replace('\n', ' ').strip() + "'"


def num1(v):
    return 'NULL' if pd.isna(v) else f'{v:.1f}'


# 작성자: 정서진 — station INSERT
def station_sql():
    s = pd.read_csv(os.path.join(HERE, 'selected_stations.csv'), encoding='utf-8-sig')
    rows = []
    for _, r in s.iterrows():
        rack = int(np.nan_to_num(r.rack_lcd) + np.nan_to_num(r.rack_qr))
        inst = pd.to_datetime(r.installed, errors='coerce')
        inst = sql_str(inst.strftime('%Y-%m-%d')) if pd.notna(inst) else 'NULL'
        park = sql_str(r.hangang_park) if isinstance(r.hangang_park, str) else 'NULL'
        rows.append(f"  ({r.sid}, {sql_str(r.st_id)}, {sql_str(r['name'])}, {r.district_id}, {sql_str(r.addr)}, "
                    f"{r.lat:.7f}, {r.lon:.7f}, {rack}, {inst}, {park}, '정상')")
    return ("-- station : 따릉이 대여소 45개 (9개 구 x 상위2·중간1·하위2, 2024 연간 대여건수 기준)\n"
            "-- 작성자: 정서진 (2. 데이터)\n"
            "-- station_code(ST-ID): 따릉이대여소 마스터 정보 좌표로 가장 가까운 대여소 매칭\n"
            "-- 출처: 서울 열린데이터광장 OA-13252 대여소 정보(24.12), OA-15249 대여소별 이용정보(월별) 2024\n"
            "INSERT INTO station (station_id, station_code, station_name, district_id, address, latitude, longitude, "
            "rack_count, installed_date, hangang_park, status) VALUES\n" + ',\n'.join(rows) + ';\n'), s.sid.tolist()


# 작성자: 정서진 — weather INSERT (ASOS 일자료)
def weather_sql():
    files = sorted(glob.glob(os.path.join(RAW, 'OBS_ASOS_DD_*.csv')))
    if not files:
        sys.exit('data/raw/ 에 OBS_ASOS_DD_*.csv 가 없어요')
    d = pd.read_csv(files[-1], encoding='cp949')
    col = {c.replace(' ', ''): c for c in d.columns}
    g = lambda name: d[col[name]]
    w = pd.DataFrame({
        'date': g('일시'), 'avg': g('평균기온(°C)'), 'mn': g('최저기온(°C)'), 'mx': g('최고기온(°C)'),
        'pr': g('일강수량(mm)').fillna(0), 'rh': g('강수계속시간(hr)').fillna(0),
        'hum': g('평균상대습도(%)'), 'wind': g('평균풍속(m/s)'), 'cloud': g('평균전운량(1/10)'),
        'new': g('합계3시간신적설(cm)').fillna(0), 'depth': g('일최심적설(cm)').fillna(0)})
    if len(w) != 366:
        sys.exit(f'ASOS 일자료가 366일이 아니에요 ({len(w)}일)')
    rows = [f"  ('{r.date}', {num1(r.avg)}, {num1(r.mn)}, {num1(r.mx)}, {r.pr:.1f}, {r.rh:.1f}, "
            f"{num1(r.hum)}, {num1(r.wind)}, {num1(r.cloud)}, {r.new:.1f}, {r.depth:.1f})" for r in w.itertuples()]
    return ("-- weather : 2024 일별 날씨 (기상청 ASOS 서울 108 일자료)\n"
            "-- 작성자: 정서진 (2. 데이터)\n"
            "-- 빈칸 = 0 : 일강수량, 강수 계속시간(hr)→rain_hours, 합계 3시간 신적설→new_snow_cm, 일 최심적설→snow_depth_cm\n"
            "INSERT INTO weather (weather_date, avg_temp, min_temp, max_temp, precipitation, rain_hours, "
            "avg_humidity, avg_wind, avg_cloud, new_snow_cm, snow_depth_cm) VALUES\n" + ',\n'.join(rows) + ';\n')


# 작성자: 정서진 — rental_daily INSERT (0건인 날 채우기)
def rental_sql(sids):
    r = pd.read_csv(os.path.join(RAW, 'rental_daily_45.csv'), encoding='utf-8-sig')
    dates = pd.date_range('2024-01-01', '2024-12-31').strftime('%Y-%m-%d')
    full = pd.MultiIndex.from_product([sorted(sids), dates], names=['station_id', 'rent_date']).to_frame(index=False)
    full = full.merge(r, how='left').fillna(0)
    rows = [f"  ({int(x.station_id)}, '{x.rent_date}', {int(x.rent_count)}, {int(x.total_minutes)}, "
            f"{int(round(x.total_distance_m))})" for x in full.itertuples()]
    out = ["-- rental_daily : 대여소 45개 x 366일 이용량 (이용 0건인 날도 0으로 채움)",
           "-- 작성자: 정서진 (2. 데이터)",
           "-- 출처: 서울 열린데이터광장 OA-15246 공공자전거 이용정보(일별) 2024, (대여일자, 대여소번호)로 SUM, 이동거리는 m 단위 반올림"]
    for i in range(0, len(rows), 1000):            # INSERT 한 문장이 너무 길지 않게 1000행씩
        out.append("INSERT INTO rental_daily (station_id, rent_date, rent_count, total_minutes, total_distance_m) VALUES")
        out.append(',\n'.join(rows[i:i + 1000]) + ';')
    return '\n'.join(out) + '\n', len(full)


def strip_set_names(text):
    return '\n'.join(l for l in text.split('\n') if not l.startswith('SET NAMES'))


if __name__ == '__main__':
    st, sids = station_sql()
    rental, n = rental_sql(sids)
    parts = [
        strip_set_names(read_text(os.path.join(ROOT, 'sql', 'district_seed.sql'))),
        strip_set_names(read_text(os.path.join(ROOT, 'sql', 'calendar_2024.sql'))),
        st, weather_sql(), rental,
        read_text(os.path.join(HERE, 'user_account_insert.sql')),
    ]
    head = ("-- =====================================================================\n"
            "-- dbinsert.sql : 샘플 데이터 초기화 스크립트 (스키마 v2 기준)\n"
            "-- 작성자: 정서진 (2. 데이터)  /  district·calendar 부분 작성자: 김수연\n"
            "-- 실행 순서: dbcreate.sql → dbinsert.sql\n"
            "-- 넣는 순서: district → calendar → station → weather → rental_daily → user_account\n"
            "-- 생성: python data/make_dbinsert.py\n"
            "-- =====================================================================\n"
            "SET NAMES utf8mb4;\nUSE team06;\n")
    out = os.path.join(ROOT, 'sql', 'dbinsert.sql')
    with open(out, 'w', encoding='utf-8', newline='\n') as f:
        f.write(head + ''.join('\n' + p.rstrip('\n') + '\n' for p in parts))
    print(f'완료: {out} (rental_daily {n}행)')
