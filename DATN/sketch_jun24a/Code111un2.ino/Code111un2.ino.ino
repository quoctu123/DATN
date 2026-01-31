#include <SPI.h>
#include <MFRC522.h>
#include <ESP32Servo.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <HTTPClient.h>

// WiFi
const char* ssid = "Hoichimi";
const char* password = "12345678";

// Server PHP
const char* serverURL = "http://10.144.200.114/parking/logic.php";

// ==== ESP32 Pin Definitions ====
// RFID 1 (cổng vào)
#define SS_IN      5
#define RST_IN     4

// RFID 2 (cổng ra)
#define SS_OUT     12
#define RST_OUT    27

// Servo
#define SERVO_IN   33
#define SERVO_OUT  26

// Buzzer
#define BUZZER_PIN 32

LiquidCrystal_I2C lcd(0x27, 16, 2);
MFRC522 rfidIn(SS_IN, RST_IN);
MFRC522 rfidOut(SS_OUT, RST_OUT);

Servo servoIn;
Servo servoOut;  

//  Số chỗ trống 
int carSlots = 3;
int bikeSlots = 5;

String parkedCars[3];
String parkedBikes[5];

unsigned long lastReadTimeIn = 0;
unsigned long lastReadTimeOut = 0;
String lastRFIDIn = "";
String lastRFIDOut = "";
const unsigned long debounceTime = 2500;

struct ServoGate {
  Servo* servo;
  unsigned long openTime;
  bool isOpen;
};
ServoGate gateIn, gateOut;
const unsigned long gateDuration = 2000;

void setup() {
  Serial.begin(115200);

  SPI.begin();
  rfidIn.PCD_Init();
  rfidOut.PCD_Init();

  servoIn.attach(SERVO_IN, 500, 2500);  
  servoOut.attach(SERVO_OUT, 500, 2500);

  servoIn.write(0);
  servoOut.write(0);

  gateIn = {&servoIn, 0, false};
  gateOut = {&servoOut, 0, false};

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  lcd.init();
  lcd.backlight();
  lcd.setCursor(0, 0);
  lcd.print("Smart Parking");
  lcd.setCursor(0, 1);
  lcd.print("Connecting...");

  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }

  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("WiFi Connected");
  delay(800);
  Serial.println("WiFi Connected");
  updateLCD();
}

void loop() {
  handleServo(gateIn);
  handleServo(gateOut);

  checkRFIDIn();   // Cổng IN
  checkRFIDOut();  // Cổng OUT
}

// Cổng IN 
void checkRFIDIn() {
  if (!rfidIn.PICC_IsNewCardPresent()) return;
  if (!rfidIn.PICC_ReadCardSerial()) return;

  String rfid = getRFID(rfidIn);
  rfid.toUpperCase();

  if (rfid == lastRFIDIn && millis() - lastReadTimeIn < debounceTime) return;

  lastRFIDIn = rfid;
  lastReadTimeIn = millis();

  Serial.println("IN: " + rfid);
  lcd.setCursor(0, 0);
  lcd.print("IN: " + rfid + "     ");

  // Gọi server và lấy loại xe
  String vehicleType = "";
  if (checkServer(rfid, vehicleType)) { // VALID và trả vehicleType
    vehicleType.trim(); // loại bỏ khoảng trắng thừa

    // Kiểm tra xem RFID đã đỗ chưa, theo loại xe thực
    if (isRFIDParked(rfid, vehicleType)) {
      lcd.setCursor(0, 1);
      lcd.print("Already Parked ");
      Serial.println("Already Parked");
      buzzBuzzer(500);
    } else {
      handleEntry(rfid, vehicleType, gateIn);  // trừ slot đúng loại
    }
  } else {
    lcd.setCursor(0, 1);
    lcd.print("Invalid Card");
    Serial.println("Invalid Card");
    buzzBuzzer(800);
  }

  updateLCD();
  rfidIn.PICC_HaltA();
  rfidIn.PCD_StopCrypto1();
}

//  Cổng OUT 
void checkRFIDOut() {
  if (!rfidOut.PICC_IsNewCardPresent()) return;
  if (!rfidOut.PICC_ReadCardSerial()) return;

  String rfid = getRFID(rfidOut);
  rfid.toUpperCase();

  if (rfid == lastRFIDOut && millis() - lastReadTimeOut < debounceTime) return;

  lastRFIDOut = rfid;
  lastReadTimeOut = millis();

  Serial.println("OUT: " + rfid);
  lcd.setCursor(0, 0);
  lcd.print("OUT: " + rfid + "    ");

  // Gọi server và lấy loại xe
  String vehicleType = "";
  if (checkServer(rfid, vehicleType)) { // VALID và trả vehicleType
    vehicleType.trim(); // loại bỏ khoảng trắng thừa

    // Kiểm tra xem RFID đã đỗ chưa
    if (isRFIDParked(rfid, vehicleType)) {
      // Xe đã đỗ → xử lý exit và tăng slot đúng loại
      handleExit(rfid, vehicleType, gateOut);
    } else {
      // Xe chưa đỗ → báo lỗi
      lcd.setCursor(0, 1);
      lcd.print("Not Parked Yet ");
      Serial.println("Not Parked Yet");
      buzzBuzzer(800);
    }
  } else {
    lcd.setCursor(0, 1);
    lcd.print("Invalid Card");
    Serial.println("Invalid Card");
    buzzBuzzer(800);
  }

  updateLCD();
  rfidOut.PICC_HaltA();
  rfidOut.PCD_StopCrypto1();
}
//  Server check 
bool checkServer(String rfid, String &vehicleType) {
  HTTPClient http;
  http.begin(serverURL);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String postData = "rfid=" + rfid + "&status=CHECK&vehicle_type=none";
  int code = http.POST(postData);
  String response = http.getString();
  http.end();
  response.trim();
  Serial.println("Server: " + response);

  // Nếu server trả "VALID,car" hoặc "VALID,motorbike"
  if (response.startsWith("VALID")) {
    int commaIndex = response.indexOf(',');
    if (commaIndex > 0) {
      vehicleType = response.substring(commaIndex + 1);
      vehicleType.trim(); // loại bỏ khoảng trắng
      return true;
    } else {
      Serial.println("ERROR: server did not return vehicle type!");
      return false; // không fallback "car"
    }
  } else {
    return false;
  }
}
// PARKING LOGIC
void handleEntry(String rfid, String type, ServoGate &servoGate) {
  bool hasSlot = (type == "car") ? (carSlots > 0) : (bikeSlots > 0);

  if (!hasSlot) {
    lcd.setCursor(0, 1);
    lcd.print("FULL SLOT     ");
    buzzBuzzer(1500);
    return;
  }

  lcd.setCursor(0, 1);
  lcd.print("Welcome!         ");
  buzzBuzzer(300);
  openGate(servoGate);

  if (type == "car") 
  carSlots--;
  else bikeSlots--;

  addParkedRFID(rfid, type);
  logAction(rfid, "in", type);
  updateLCD();
}

void handleExit(String rfid, String type, ServoGate &servoGate) {
  lcd.setCursor(0, 1);
  lcd.print("See you!         ");
  buzzBuzzer(300);
  openGate(servoGate);

  if (type == "car") carSlots++;
  else bikeSlots++;

  removeParkedRFID(rfid, type);
  logAction(rfid, "out", type);
  updateLCD();
}

// HARDWARE FUNCTIONS 
String getRFID(MFRC522 &reader) {
  String rfid = "";
  for (byte i = 0; i < reader.uid.size; i++) {
    if (reader.uid.uidByte[i] < 0x10) rfid += "0";
    rfid += String(reader.uid.uidByte[i], HEX);
  }
  return rfid;
}

void openGate(ServoGate &gate){
  if(!gate.isOpen){      // chỉ mở nếu chưa mở
    gate.servo->write(90);
    gate.openTime = millis();
    gate.isOpen = true;
  }
}

void handleServo(ServoGate &gate) {
  if (gate.isOpen && millis() - gate.openTime >= gateDuration) {
    gate.servo->write(0);
    gate.isOpen = false;
  }
}

void buzzBuzzer(unsigned int duration) {
  digitalWrite(BUZZER_PIN, HIGH);
  delay(duration);
  digitalWrite(BUZZER_PIN, LOW);
}

void updateLCD() { 
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Smart Parking   ");
  lcd.setCursor(0, 1);
  lcd.print("Oto:" + String(carSlots) + " Xe:" + String(bikeSlots) + "   ");
}

//  ARRAYS
bool isRFIDParked(String rfid, String type) {
  String* arr = (type == "car") ? parkedCars : parkedBikes;
  int size = (type == "car") ? 3 : 5;

  for (int i = 0; i < size; i++)
    if (arr[i] == rfid) return true;

  return false;
}

void addParkedRFID(String rfid, String type) {
  String* arr = (type == "car") ? parkedCars : parkedBikes;
  int size = (type == "car") ? 3 : 5;

  for (int i = 0; i < size; i++)
    if (arr[i] == "") {
      arr[i] = rfid;
      return;
    }
}

void removeParkedRFID(String rfid, String type) {
  String* arr = (type == "car") ? parkedCars : parkedBikes;
  int size = (type == "car") ? 3 : 5;

  for (int i = 0; i < size; i++)
    if (arr[i] == rfid) {
      arr[i] = "";
      return;
    }
}

void logAction(String rfid, String status, String vehicleType) {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(serverURL);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    String postData = "rfid=" + rfid + "&status=" + status + "&vehicle_type=" + vehicleType;
    int code = http.POST(postData);
    Serial.print("HTTP CODE: ");
    Serial.println(code);

    String response = http.getString();
    Serial.print("Server (");
    Serial.print(code);
    Serial.print("): ");
    Serial.println(response);
    http.end();
  }
}
