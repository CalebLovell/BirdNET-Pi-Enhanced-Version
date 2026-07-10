import sqlite3
import requests
import os
import logging
import time
from datetime import datetime
import sys
sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from helpers import DB_PATH, get_settings

logging.basicConfig(level=logging.INFO)
log = logging.getLogger('weather')

# Open-Meteo occasionally times out or returns 5xx; without retries a failed
# hourly cron run left a permanent gap in the weather table. Retry a few
# times, and fetch a week of history (the API serves it freely) so any
# successful run backfills gaps from Pi downtime or earlier failed runs.
FETCH_ATTEMPTS = 3
RETRY_DELAY_SECONDS = [30, 60]
PAST_DAYS = 7

def fetch_hourly(lat, lon):
    url = (f"https://api.open-meteo.com/v1/forecast?latitude={lat}&longitude={lon}"
           "&hourly=temperature_2m,weather_code,is_day,wind_speed_10m,wind_direction_10m"
           f"&temperature_unit=fahrenheit&wind_speed_unit=mph&past_days={PAST_DAYS}"
           "&forecast_days=1&timezone=auto")
    for attempt in range(1, FETCH_ATTEMPTS + 1):
        try:
            response = requests.get(url, timeout=15)
            response.raise_for_status()
            return response.json()
        except Exception as e:
            if attempt < FETCH_ATTEMPTS:
                delay = RETRY_DELAY_SECONDS[min(attempt, len(RETRY_DELAY_SECONDS)) - 1]
                log.warning(f"Weather fetch attempt {attempt}/{FETCH_ATTEMPTS} failed ({e}); retrying in {delay}s.")
                time.sleep(delay)
            else:
                log.error(f"Weather fetch failed after {FETCH_ATTEMPTS} attempts: {e}")
    return None

def update_weather():
    conf = get_settings()
    lat = conf.get('LATITUDE', None)
    lon = conf.get('LONGITUDE', None)

    if lat is None or lon is None or lat == '' or lon == '':
        log.error("Latitude or Longitude not set. Cannot fetch weather.")
        return

    data = fetch_hourly(lat, lon)
    if data is None:
        return

    # Parse data
    times = data['hourly']['time']
    temps = data['hourly']['temperature_2m']
    codes = data['hourly']['weather_code']
    is_days = data['hourly']['is_day']
    winds = data['hourly']['wind_speed_10m']
    dirs = data['hourly']['wind_direction_10m']

    # Connect to the SQLite DB
    try:
        con = sqlite3.connect(DB_PATH)
        cur = con.cursor()
        
        # Ensure the weather table exists isolated from the detections table
        cur.execute('''
            CREATE TABLE IF NOT EXISTS weather (
                Date DATE,
                Hour INT,
                Temp FLOAT,
                ConditionCode INT,
                IsDay INT,
                WindSpeed FLOAT,
                WindDirection INT,
                PRIMARY KEY(Date, Hour)
            )
        ''')
        
        # Check for new columns (for existing tables)
        cur.execute("PRAGMA table_info(weather)")
        columns = [column[1] for column in cur.fetchall()]
        if 'IsDay' not in columns:
            cur.execute("ALTER TABLE weather ADD COLUMN IsDay INT DEFAULT 1")
        if 'WindSpeed' not in columns:
            cur.execute("ALTER TABLE weather ADD COLUMN WindSpeed FLOAT")
        if 'WindDirection' not in columns:
            cur.execute("ALTER TABLE weather ADD COLUMN WindDirection INT")
        
        # Insert or replace hourly metrics
        for t, temp, code, is_day, wind, direction in zip(times, temps, codes, is_days, winds, dirs):
            if temp is None:
                continue
            dt = datetime.fromisoformat(t)
            date_str = dt.strftime('%Y-%m-%d')
            hour = dt.hour
            
            cur.execute("INSERT OR REPLACE INTO weather (Date, Hour, Temp, ConditionCode, IsDay, WindSpeed, WindDirection) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        (date_str, hour, temp, code, is_day, wind, direction))
                        
        con.commit()
        con.close()
        log.info("Hourly weather data synced successfully to birds.db.")
    except Exception as e:
        log.error(f"Database error writing weather: {e}")

if __name__ == '__main__':
    update_weather()
