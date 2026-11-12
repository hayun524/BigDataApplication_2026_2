"""
gen_calendar.py : calendar 테이블용 2024년 366일 INSERT 문 생성
작성자 : 김수연 (1. DB 설계)

사용법 : python3 gen_calendar.py > ../sql/data/calendar_2024.sql
공휴일 : 관공서의 공휴일에 관한 규정 기준 2024년 (대체공휴일, 선거일, 임시공휴일 포함)
"""
from datetime import date, timedelta

HOLIDAYS_2024 = {
    date(2024, 1, 1): "신정",
    date(2024, 2, 9): "설날 연휴",
    date(2024, 2, 10): "설날",
    date(2024, 2, 11): "설날 연휴",
    date(2024, 2, 12): "대체공휴일(설날)",
    date(2024, 3, 1): "삼일절",
    date(2024, 4, 10): "제22대 국회의원선거",
    date(2024, 5, 5): "어린이날",
    date(2024, 5, 6): "대체공휴일(어린이날)",
    date(2024, 5, 15): "부처님오신날",
    date(2024, 6, 6): "현충일",
    date(2024, 8, 15): "광복절",
    date(2024, 9, 16): "추석 연휴",
    date(2024, 9, 17): "추석",
    date(2024, 9, 18): "추석 연휴",
    date(2024, 10, 1): "국군의 날(임시공휴일)",
    date(2024, 10, 3): "개천절",
    date(2024, 10, 9): "한글날",
    date(2024, 12, 25): "성탄절",
}

DAY_NAMES = "일월화수목금토"  # DAYOFWEEK 1=일 ... 7=토


# 작성자: 김수연 — 월 → 계절 (3~5 봄, 6~8 여름, 9~11 가을, 12~2 겨울)
def season_of(month: int) -> str:
    if month in (3, 4, 5):
        return "봄"
    if month in (6, 7, 8):
        return "여름"
    if month in (9, 10, 11):
        return "가을"
    return "겨울"


# 작성자: 김수연 — 2024년 366일 calendar INSERT문 출력
def main() -> None:
    print("-- calendar_2024.sql : 2024-01-01 ~ 2024-12-31 (366일)")
    print("-- 작성자 : 김수연 / data/gen_calendar.py 로 생성")
    print("-- day_type 은 생성 컬럼이라 INSERT 하지 않음")
    print("SET NAMES utf8mb4;")
    print("INSERT INTO calendar (cal_date, year, month, day, day_of_week, day_name, season, is_holiday, holiday_name) VALUES")
    rows = []
    d = date(2024, 1, 1)
    while d.year == 2024:
        dow = (d.isoweekday() % 7) + 1  # 월=1..일=7 → 일=1..토=7
        name = HOLIDAYS_2024.get(d)
        hol = "NULL" if name is None else f"'{name}'"
        rows.append(
            f"  ('{d.isoformat()}', {d.year}, {d.month}, {d.day}, {dow}, "
            f"'{DAY_NAMES[dow - 1]}', '{season_of(d.month)}', {1 if name else 0}, {hol})"
        )
        d += timedelta(days=1)
    print(",\n".join(rows) + ";")


if __name__ == "__main__":
    main()
