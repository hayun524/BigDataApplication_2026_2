# 작성자: 정서진 (2. 데이터)
# 따릉이 이용정보(일별) 2024 월별 파일에서 선정 대여소 45개만 골라
# (대여일자, 대여소번호)별로 이용건수·이용시간·이동거리를 합친다.

import glob, sys
import pandas as pd

STATIONS = {383,431,332,381,429,583,565,513,4363,3527,4065,1721,1751,1731,1730,
            4217,113,4214,4240,3003,907,996,976,966,945,230,207,227,4583,200,
            2102,2173,3819,2130,2199,2377,2340,2422,3648,2391,1009,1050,1073,1039,3539}

def find(cols, *keys):
    for c in cols:
        if all(k in c.replace(' ', '') for k in keys):
            return c
    sys.exit(f'컬럼을 못 찾음: {keys} / 실제 컬럼: {list(cols)}')

files = sorted(glob.glob('*이용정보(일별)_24*.csv'))
if not files:
    sys.exit('이용정보(일별)_24xx.csv 파일이 이 폴더에 없어요')

parts = []
for f in files:
    print('처리 중:', f)
    enc = 'cp949'
    try:
        head = pd.read_csv(f, encoding=enc, nrows=0)
    except UnicodeDecodeError:
        enc = 'utf-8-sig'
        head = pd.read_csv(f, encoding=enc, nrows=0)
    cols = head.columns
    c_date = find(cols, '대여일자')
    c_sid = find(cols, '대여소번호')
    c_cnt = find(cols, '이용건수')
    c_min = find(cols, '이용시간')
    c_dist = find(cols, '이동거리')
    for chunk in pd.read_csv(f, encoding=enc, usecols=[c_date, c_sid, c_cnt, c_min, c_dist],
                             dtype=str, chunksize=500_000):
        chunk[c_sid] = pd.to_numeric(chunk[c_sid].str.strip(), errors='coerce')
        chunk = chunk[chunk[c_sid].isin(STATIONS)]
        if chunk.empty:
            continue
        for c in (c_cnt, c_min, c_dist):
            chunk[c] = pd.to_numeric(chunk[c].str.replace(',', ''), errors='coerce').fillna(0)
        chunk = chunk.rename(columns={c_date: 'rent_date', c_sid: 'station_id', c_cnt: 'rent_count',
                                      c_min: 'total_minutes', c_dist: 'total_distance_m'})
        parts.append(chunk)

df = pd.concat(parts)
df['rent_date'] = pd.to_datetime(df['rent_date'].str.strip(), format='mixed').dt.strftime('%Y-%m-%d')
out = df.groupby(['station_id', 'rent_date'], as_index=False)[['rent_count', 'total_minutes', 'total_distance_m']].sum()
out['station_id'] = out['station_id'].astype(int)
out.to_csv('rental_daily_45.csv', index=False, encoding='utf-8-sig')
print(f'완료: rental_daily_45.csv ({len(out)}행, 대여소 {out.station_id.nunique()}개, {out.rent_date.min()} ~ {out.rent_date.max()})')
