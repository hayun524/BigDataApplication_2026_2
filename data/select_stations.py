# 작성자: 정서진 (2. 데이터)
# 9개 자치구에서 대여소 45개를 고르고, ST-ID(station_code)를 좌표로 매칭한다.
#
# 선정 기준
#   - 2024년 12개월 모두 이용 기록이 있는 대여소만 후보
#   - 자치구마다 2024 연간 대여건수 순위로 상위 2 · 중간 1 · 하위 2 (= 5개)
#   - 한강공원 대여소: 망원(마포, 상위1), 여의도(영등포, 상위2)는 순위로 자연히 포함
#                      영등포 '중간' 자리는 양화한강공원(양평2나들목, 227)으로 교체
#   - 강동구는 광나루한강공원 안에 대여소가 없어 한강공원 표시 없음
#
# 입력 (data/raw/ 에 두기, 커밋 안 함)
#   - 공공자전거 대여소 정보(24.12월 기준).xlsx      (열린데이터광장 OA-13252)
#   - 서울특별시 공공자전거 대여소별 이용정보(월별)_24.1-6.csv, _24.7-12.csv  (OA-15249)
#   - 서울시 따릉이대여소 마스터 정보 csv            (대여소_ID, 주소1, 주소2, 위도, 경도)
# 출력
#   - data/selected_stations.csv  → make_dbinsert.py, filter_daily.py 에서 사용
#
# 실행 (레포 최상위 폴더에서):  python data/select_stations.py
import glob
import os
import sys

import numpy as np
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
RAW = os.path.join(HERE, 'raw')

DISTRICTS = ['중구', '성동구', '도봉구', '마포구', '은평구', '영등포구', '관악구', '강남구', '강동구']
DISTRICT_ID = {'중구': 11140, '성동구': 11200, '도봉구': 11320, '마포구': 11440, '은평구': 11380,
               '영등포구': 11560, '관악구': 11620, '강남구': 11680, '강동구': 11740}
HANGANG = {4217: '망원', 207: '여의도', 227: '양화'}          # 대여소번호 → 한강공원 이름
REPLACE = {('영등포구', '중간'): 227}                         # (자치구, 자리) → 바꿔 넣을 대여소번호


def one(pattern):
    files = sorted(glob.glob(os.path.join(RAW, pattern)))
    if not files:
        sys.exit(f'data/raw/ 에 파일이 없어요: {pattern}')
    return files


# 작성자: 정서진 — 1) 월별 이용정보로 2024 연간 대여건수 집계
def yearly_rentals():
    m = pd.concat([pd.read_csv(f, encoding='cp949') for f in one('*대여소별 이용정보(월별)_24*.csv')])
    m['sid'] = m['대여소명'].str.extract(r'^\s*(\d+)\.')[0].astype('Int64')
    m['name'] = m['대여소명'].str.replace(r'^\s*\d+\.\s*', '', regex=True)
    m = m[m['자치구'].isin(DISTRICTS)]
    g = m.groupby(['자치구', 'sid', 'name']).agg(months=('기준년월', 'nunique'), rent=('대여건수', 'sum')).reset_index()
    return g[g.months == 12].copy()


# 작성자: 정서진 — 2) 자치구별 상위2·중간1·하위2 선정
def pick(f):
    f['rank'] = f.groupby('자치구').rent.rank(ascending=False, method='first').astype(int)
    rows = []
    for gu, d in f.groupby('자치구'):
        n = len(d)
        mid = (n + 1) // 2
        for r, slot in {1: '상위1', 2: '상위2', mid: '중간', n - 1: '하위1', n: '하위2'}.items():
            s = d[d['rank'] == r].iloc[0]
            if (gu, slot) in REPLACE:
                s = d[d.sid == REPLACE[(gu, slot)]].iloc[0]
                slot += '(한강)'
            rows.append(dict(gu=gu, sid=int(s.sid), slot=slot, rent_2024=int(s.rent), rank=int(s['rank'])))
    return pd.DataFrame(rows)


# 작성자: 정서진 — 3) 대여소 정보(xlsx)에서 주소·좌표·거치대수 붙이기
def station_info():
    x = pd.read_excel(one('*대여소 정보(24.12월 기준)*.xlsx')[0], header=None, skiprows=5)
    x = x.iloc[:, :9]
    x.columns = ['sid', 'name', 'gu_x', 'addr', 'lat', 'lon', 'installed', 'rack_lcd', 'rack_qr']
    x['sid'] = pd.to_numeric(x.sid, errors='coerce')
    x['name'] = x['name'].astype(str).str.strip()
    return x.dropna(subset=['sid']).drop(columns='gu_x')


# 작성자: 정서진 — 4) 마스터 정보 좌표로 가장 가까운 ST-ID 매칭 (하버사인 거리)
def match_st_id(sel):
    m = pd.read_csv(one('*마스터*.csv')[0], encoding='cp949')
    m = m[(m['위도'] > 30) & (m['경도'] > 120)]                  # 좌표 0 인 행 제외
    la1, lo1 = np.radians(sel.lat.values)[:, None], np.radians(sel.lon.values)[:, None]
    la2, lo2 = np.radians(m['위도'].values)[None, :], np.radians(m['경도'].values)[None, :]
    a = np.sin((la2 - la1) / 2) ** 2 + np.cos(la1) * np.cos(la2) * np.sin((lo2 - lo1) / 2) ** 2
    dist = 2 * 6371000 * np.arcsin(np.sqrt(a))
    near = dist.argmin(axis=1)
    sel['st_id'] = m['대여소_ID'].values[near]
    sel['st_dist_m'] = dist[np.arange(len(sel)), near].round(1)
    if sel.st_id.duplicated().any():
        sys.exit('ST-ID가 겹치는 대여소가 있어요. 좌표를 확인하세요.')
    return sel


if __name__ == '__main__':
    sel = pick(yearly_rentals())
    sel = sel.merge(station_info(), on='sid', how='left')
    sel['hangang_park'] = sel.sid.map(HANGANG)
    sel['district_id'] = sel.gu.map(DISTRICT_ID)
    sel['order'] = sel.gu.map({g: i for i, g in enumerate(DISTRICTS)})
    sel = match_st_id(sel.sort_values(['order', 'rank']).reset_index(drop=True))
    assert len(sel) == 45 and sel.sid.is_unique and sel.lat.notna().all()

    cols = ['gu', 'district_id', 'sid', 'st_id', 'name', 'slot', 'rent_2024', 'hangang_park',
            'addr', 'lat', 'lon', 'installed', 'rack_lcd', 'rack_qr', 'st_dist_m']
    out = os.path.join(HERE, 'selected_stations.csv')
    sel[cols].to_csv(out, index=False, encoding='utf-8-sig')
    print(f'완료: {out} (대여소 {len(sel)}개, ST-ID 최대 거리 {sel.st_dist_m.max()}m)')
