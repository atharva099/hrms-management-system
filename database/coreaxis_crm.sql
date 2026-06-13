-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Jun 13, 2026 at 11:32 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `coreaxis_crm`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) DEFAULT NULL,
  `attendance_date` date DEFAULT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `working_hours` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `employee_id`, `attendance_date`, `check_in`, `check_out`, `status`, `working_hours`) VALUES
(1, 'EMP001', '2026-05-08', '14:19:13', '14:40:53', 'Half Day', '0 hrs 21 mins'),
(2, 'EMP001', '2026-05-09', '05:59:37', '18:54:27', 'Present', '12 hrs 54 mins'),
(3, 'EMP001', '2026-05-11', '09:54:38', '09:55:17', 'Half Day', '0 hrs 0 mins'),
(4, 'EMP002', '2026-05-11', '09:56:53', '15:49:39', 'Late', '5 hrs 52 mins'),
(5, 'EMP001', '2026-05-12', '08:43:36', '19:42:57', 'Present', '10 hrs 59 mins'),
(6, 'EMP002', '2026-05-12', '08:43:51', '19:42:44', 'Present', '10 hrs 58 mins'),
(7, 'EMP003', '2026-05-12', '09:56:27', NULL, 'Late', NULL),
(8, 'EMP004', '2026-05-12', NULL, NULL, 'Absent', NULL),
(9, 'EMP002', '2026-05-13', '10:27:03', '19:25:48', 'Present', '8 hrs 58 mins'),
(10, 'EMP001', '2026-05-13', '10:28:07', '19:25:19', 'Late', '8 hrs 57 mins'),
(12, 'EMP002', '2026-05-14', '09:45:43', NULL, 'Present', NULL),
(13, 'EMP001', '2026-05-14', '09:50:28', '10:10:54', 'Half Day', '0 hrs 20 mins'),
(14, 'EMP003', '2026-05-14', '10:17:55', '10:18:04', 'Half Day', '0 hrs 0 mins'),
(18, 'EMP002', '2026-05-17', NULL, NULL, 'Leave', NULL),
(19, 'EMP003', '2026-05-17', NULL, NULL, 'Absent', NULL),
(20, 'EMP004', '2026-05-17', NULL, NULL, 'Absent', NULL),
(21, 'EMP001', '2026-05-17', '15:38:47', '20:34:00', 'Present', '4 hrs 55 mins'),
(22, 'EMP002', '2026-05-18', NULL, NULL, 'Leave', NULL),
(23, 'EMP001', '2026-05-30', '12:08:20', NULL, 'Late', NULL),
(24, 'EMP002', '2026-05-30', '15:45:05', NULL, 'Present', NULL),
(25, 'EMP001', '2026-05-31', '11:00:02', '18:05:45', 'Present', '7 hrs 5 mins'),
(33, 'EMP002', '2026-06-06', '14:20:40', '14:21:00', 'Half Day', '0 hrs 0 mins'),
(34, 'EMP1001', '2026-06-06', '14:23:19', '14:23:25', 'Half Day', '0 hrs 0 mins'),
(35, 'EMP001', '2026-06-07', '15:55:16', '15:55:30', 'Sunday Working', '0 hrs 0 mins');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `department_name`, `status`, `created_at`) VALUES
(1, 'Management', 'Active', '2026-06-02 14:10:21'),
(2, 'HR', 'Active', '2026-06-02 14:10:21'),
(3, 'Accounts & Finance', 'Active', '2026-06-02 14:10:21'),
(4, 'IT', 'Active', '2026-06-02 14:10:21'),
(5, 'Sales', 'Active', '2026-06-02 14:10:21'),
(6, 'Operations', 'Active', '2026-06-02 14:10:21'),
(7, 'Marketing', 'Active', '2026-06-02 14:18:11'),
(8, 'Legal', 'Active', '2026-06-02 14:56:16'),
(9, 'Procurrent', 'Active', '2026-06-03 16:53:55'),
(10, 'Testing Department', 'Inactive', '2026-06-03 17:04:18');

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `holiday_name` varchar(100) DEFAULT NULL,
  `holiday_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `holidays`
--

INSERT INTO `holidays` (`id`, `holiday_name`, `holiday_date`) VALUES
(1, 'Bakrid', '2026-05-27'),
(2, 'Sunday', '2026-05-10'),
(3, 'Republic Day', '2026-01-26'),
(4, 'Holi', '2026-03-14'),
(5, 'Independence Day', '2026-08-15'),
(6, 'Diwali', '2026-11-08'),
(7, 'Christmas', '2026-12-25'),
(8, 'Sunday', '2026-05-17'),
(9, 'Holiday', '2026-06-06');

-- --------------------------------------------------------

--
-- Table structure for table `leaves`
--

CREATE TABLE `leaves` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `leave_type` varchar(100) DEFAULT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `applied_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `employee_notification_status` varchar(20) DEFAULT 'Unread'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leaves`
--

INSERT INTO `leaves` (`id`, `employee_id`, `leave_type`, `from_date`, `to_date`, `reason`, `status`, `applied_on`, `employee_notification_status`) VALUES
(1, 'EMP001', 'Sick Leave', '2026-05-10', '2026-05-10', 'Personal reason', 'Approved', '2026-05-09 11:27:13', 'Unread'),
(2, 'EMP001', 'Sick Leave', '2026-05-10', '2026-05-10', 'Personal reason', 'Rejected', '2026-05-09 11:44:45', 'Unread'),
(3, 'EMP002', 'Casual Leave', '2026-05-11', '2026-05-11', 'xyz', 'Rejected', '2026-05-10 10:41:23', 'Read'),
(4, 'EMP002', 'Casual Leave', '2026-05-12', '2026-05-12', 'personal reason', 'Approved', '2026-05-11 07:57:40', 'Read'),
(5, 'EMP002', 'Paid Leave', '2026-05-13', '2026-05-13', 'asfsd', 'Rejected', '2026-05-12 15:46:09', 'Read'),
(6, 'EMP002', 'Sick Leave', '2026-05-12', '2026-05-12', 'afffsfa', 'Approved', '2026-05-12 16:12:41', 'Read'),
(7, 'EMP002', 'Sick Leave', '2026-05-12', '2026-05-12', 'sdfsdf', 'Rejected', '2026-05-12 16:21:43', 'Read'),
(8, 'EMP002', 'Sick Leave', '2026-05-12', '2026-05-12', 'affafaf', 'Approved', '2026-05-12 16:56:27', 'Read'),
(9, 'EMP002', 'Casual Leave', '2026-05-13', '2026-05-13', 'sfa', 'Rejected', '2026-05-13 04:44:09', 'Read'),
(10, 'EMP002', 'Sick Leave', '2026-05-13', '2026-05-13', 'dfgdfg', 'Rejected', '2026-05-13 04:54:21', 'Read'),
(11, 'EMP002', 'Sick Leave', '2026-05-13', '2026-05-13', 'dfgdfg', 'Rejected', '2026-05-13 04:54:27', 'Read'),
(12, 'EMP002', 'Sick Leave', '2026-05-13', '2026-05-13', 'fsdfs', 'Rejected', '2026-05-13 04:56:04', 'Read'),
(13, 'EMP002', 'Sick Leave', '2026-05-15', '2026-05-15', 'hggvhh', 'Rejected', '2026-05-15 08:21:47', 'Read'),
(14, 'EMP002', 'Sick Leave', '2026-05-17', '2026-05-17', 'qwrwrewewr', 'Approved', '2026-05-17 08:57:14', 'Read'),
(15, 'EMP002', 'sick leave', '2026-05-17', '2026-05-17', 'fefefefewf', 'Approved', '2026-05-17 09:00:21', 'Read'),
(16, 'EMP002', 'Sick Leave', '2026-05-18', '2026-05-18', 'hjjjjkkydrc', 'Rejected', '2026-05-18 16:38:30', 'Read'),
(17, 'EMP002', 'Sick Leave', '2026-05-18', '2026-05-18', 'fsdfsdsd', 'Approved', '2026-05-18 16:39:38', 'Read'),
(18, 'EMP002', 'Casual Leave', '2026-05-19', '2026-05-19', 'afffsfsd', 'Rejected', '2026-05-19 10:00:29', 'Read'),
(19, 'EMP002', 'Sick Leave', '2026-05-19', '2026-05-19', 'ffsfffqewd', 'Rejected', '2026-05-19 10:08:18', 'Read'),
(20, 'EMP002', 'Sick Leave', '2026-05-19', '2026-05-19', 'fgfdgf', 'Rejected', '2026-05-19 10:21:38', 'Read');

-- --------------------------------------------------------

--
-- Table structure for table `salaries`
--

CREATE TABLE `salaries` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) DEFAULT NULL,
  `salary_month` varchar(20) DEFAULT NULL,
  `basic_salary` decimal(10,2) DEFAULT NULL,
  `incentive` decimal(10,2) DEFAULT NULL,
  `late_deduction` decimal(10,2) DEFAULT NULL,
  `halfday_deduction` decimal(10,2) DEFAULT NULL,
  `final_salary` decimal(10,2) DEFAULT NULL,
  `absent_deduction` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_status` varchar(20) DEFAULT 'Paid',
  `payment_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `salaries`
--

INSERT INTO `salaries` (`id`, `employee_id`, `salary_month`, `basic_salary`, `incentive`, `late_deduction`, `halfday_deduction`, `final_salary`, `absent_deduction`, `created_at`, `payment_status`, `payment_date`) VALUES
(1, 'EMP001', 'May 2026', 500000.00, 5000.00, 0.00, 0.00, 505000.00, NULL, '2026-05-31 09:46:02', 'Paid', '2026-06-01'),
(2, 'EMP001', 'April 2026', 50000.00, 5000.00, 0.00, 0.00, 55000.00, NULL, '2026-05-31 09:46:02', 'Paid', '2026-06-01'),
(3, 'EMP001', 'May 2026', 50000.00, 7000.00, 0.00, 0.00, 57000.00, NULL, '2026-05-31 09:46:02', 'Paid', '2026-06-01'),
(4, 'EMP002', 'April 2026', 30000.00, 2000.00, 0.00, 0.00, 32000.00, NULL, '2026-05-31 09:46:02', 'Paid', '2026-06-01'),
(5, 'EMP002', 'May 2026', 30000.00, 3500.00, 0.00, 0.00, 33500.00, NULL, '2026-05-31 09:46:02', 'Paid', '2026-06-01'),
(6, 'EMP003', 'April 2026', 50000.00, 5000.00, 0.00, 0.00, 55000.00, 0.00, '2026-05-31 09:46:02', 'Paid', '2026-05-31'),
(7, 'EMP004', 'May 2026', 50000.00, 5000.00, 0.00, 0.00, 53000.00, 2000.00, '2026-05-31 09:46:02', 'Paid', '2026-05-31'),
(8, 'EMP003', 'May 2026', 50000.00, 5000.00, 100.00, 500.00, 53400.00, 1000.00, '2026-05-31 13:54:25', 'Paid', '2026-05-31'),
(9, 'EMP004', 'April 2026', 100000.00, 10000.00, 0.00, 0.00, 110000.00, 0.00, '2026-06-01 08:05:28', 'Paid', '2026-06-01'),
(11, 'emp005', 'May 2026', 10000.00, 1000.00, 0.00, 0.00, 11000.00, 0.00, '2026-06-01 08:57:38', 'Paid', '2026-06-01');

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `shift_name` varchar(100) NOT NULL,
  `in_time` time NOT NULL,
  `out_time` time NOT NULL,
  `late_after` time NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shifts`
--

INSERT INTO `shifts` (`id`, `shift_name`, `in_time`, `out_time`, `late_after`, `status`, `created_at`) VALUES
(8, 'General Shift Updated', '09:00:00', '18:00:00', '09:15:00', 'Active', '2026-06-02 15:32:07'),
(9, 'Morning Shift', '06:00:00', '14:00:00', '06:15:00', 'Active', '2026-06-02 15:32:07'),
(10, 'Night Shift', '22:00:00', '06:00:00', '22:15:00', 'Inactive', '2026-06-02 15:32:07'),
(12, 'Test Shift', '09:00:00', '18:00:00', '09:15:00', 'Active', '2026-06-02 15:45:57'),
(13, 'Night Shift 2', '22:00:00', '06:00:00', '22:15:00', 'Active', '2026-06-02 15:47:18'),
(14, 'Evening Shift', '18:00:00', '04:00:00', '18:15:00', 'Active', '2026-06-02 15:51:45');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `password` varchar(100) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `designation` varchar(50) DEFAULT NULL,
  `shift_time` time DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT NULL,
  `incentive` decimal(10,2) DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `role` varchar(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `employee_id`, `full_name`, `password`, `department`, `designation`, `shift_time`, `salary`, `incentive`, `joining_date`, `role`, `status`, `phone`, `email`, `shift_id`) VALUES
(1, 'EMP001', 'Atharva Wadgaonkar', '$2y$10$K.ZIP8.ECJTE2qCohUPGPek348yq0wjuiVgyPvKeNqnvvCPDWI5.y', 'Management', 'Admin', '09:00:00', 1000000.00, 50000.00, '2026-05-08', 'Admin', 'Active', '7499387245', 'atharvawadgaonkar6@gmail.com', 8),
(2, 'EMP002', 'Vinod Wadgaonkar', '$2y$10$dXcKvKdEEBys8cQkNed09O3p7FzWR0Mfl.ZASrEWK1Jl34KiX/NF2', 'Sales', 'Business Head', '08:00:00', 100000.00, 10000.00, '2026-05-09', 'Employee', 'Active', '9022323663', 'vinod11.wadgaonkar@gmail.com', 10),
(3, 'EMP003', 'Arjun Wadgaonkar', '$2y$10$J9JBItr6IKQB2DnxTtRNWuQiIMitYMcr65K5aG7xdhySkYk2Z3oW.', 'Management', 'HR', '06:00:00', 50000.00, 5000.00, '2026-05-12', 'Employee', 'Active', '9022942165', 'arjun@123', 9),
(4, 'EMP004', 'Krishna Wadgaonkar', '$2y$10$pN18kxO8rZD4qA5r0iGzc.AGa43/uy0qbOb.NxWY9kp5TQvUWZVJ6', 'Sales', 'HR', '09:00:00', 100000.00, 10000.00, '2026-05-12', 'Employee', 'Active', NULL, NULL, 9),
(6, 'emp005', 'abc', '$2y$10$tABebuYHP2yFID6FSe2HgOsAh.ChDSOV2HMZuCDVFF2yVjoFdGrky', 'Development', 'employee', '09:00:00', 10000.00, 1000.00, '2026-06-01', 'Employee', 'Active', NULL, NULL, NULL),
(11, 'EMP1001', 'Rahul Sharma', '$2y$10$FLEouIeTDw/.Lc0RptqKLuTAX7e3WCXhhaZ1uYCztzrVEtTdBpCZu', 'Finance', 'employee', NULL, 20000.00, NULL, '2026-06-03', 'Employee', 'Active', '11223344556', 'rahul@gmail.com', 8),
(13, 'EMP1002', 'EMPTEST', '$2y$10$N4u7jNcqvJ9RyRp3ZHGzYu.Mdku1TN0Ko0BVKiyDbyF6jMpMgWKIC', 'Procurrent', 'employee', NULL, 10000.00, NULL, '2026-06-10', 'Employee', 'Active', '122255531215', 'test@gmail.com', 8);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `leaves`
--
ALTER TABLE `leaves`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `salaries`
--
ALTER TABLE `salaries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `leaves`
--
ALTER TABLE `leaves`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `salaries`
--
ALTER TABLE `salaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
