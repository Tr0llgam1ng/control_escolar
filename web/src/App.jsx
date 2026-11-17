import { Navigate, Route, Routes } from 'react-router-dom';
import { useAuth } from './auth.jsx';
import Layout from './components/Layout.jsx';
import Login from './pages/Login.jsx';
import Account from './pages/Account.jsx';
import AdminDashboard from './pages/admin/Dashboard.jsx';
import { PeriodsPage, StudentsPage, SubjectsPage, TeachersPage } from './pages/admin/catalogPages.jsx';
import CoursesPage from './pages/admin/Courses.jsx';
import CourseDetail from './pages/admin/CourseDetail.jsx';
import MyCourses from './pages/teacher/MyCourses.jsx';
import CourseGrades from './pages/teacher/CourseGrades.jsx';
import StudentReport from './pages/student/Report.jsx';
import StudentPayments from './pages/student/Payments.jsx';
import DocumentView from './pages/student/DocumentView.jsx';
import AdminPayments from './pages/admin/Payments.jsx';
import AdminInvoices from './pages/admin/Invoices.jsx';
import InvoiceView from './pages/InvoiceView.jsx';
import Cafeteria from './pages/Cafeteria.jsx';
import Membership from './pages/student/Membership.jsx';
import Time from './pages/student/Time.jsx';
import StudyRooms from './pages/student/StudyRooms.jsx';
import Library from './pages/student/Library.jsx';
import Tutoring from './pages/student/Tutoring.jsx';
import Analytics from './pages/student/Analytics.jsx';
import TutoringRequests from './pages/teacher/TutoringRequests.jsx';
import AdminMemberships from './pages/admin/Memberships.jsx';

const HOME = { admin: '/admin', teacher: '/teacher', student: '/student' };

function RequireRole({ role, children }) {
  const { user } = useAuth();
  if (!user) return <Navigate to="/login" replace />;
  if (role && user.role !== role) return <Navigate to={HOME[user.role]} replace />;
  return children;
}

export default function App() {
  const { user } = useAuth();

  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to={HOME[user.role]} replace /> : <Login />} />

      <Route element={<RequireRole><Layout /></RequireRole>}>
        <Route path="/account" element={<Account />} />
        <Route path="/cafeteria" element={<Cafeteria />} />

        <Route path="/admin" element={<RequireRole role="admin"><AdminDashboard /></RequireRole>} />
        <Route path="/admin/periods" element={<RequireRole role="admin"><PeriodsPage /></RequireRole>} />
        <Route path="/admin/subjects" element={<RequireRole role="admin"><SubjectsPage /></RequireRole>} />
        <Route path="/admin/teachers" element={<RequireRole role="admin"><TeachersPage /></RequireRole>} />
        <Route path="/admin/students" element={<RequireRole role="admin"><StudentsPage /></RequireRole>} />
        <Route path="/admin/courses" element={<RequireRole role="admin"><CoursesPage /></RequireRole>} />
        <Route path="/admin/courses/:id" element={<RequireRole role="admin"><CourseDetail /></RequireRole>} />
        <Route path="/admin/payments" element={<RequireRole role="admin"><AdminPayments /></RequireRole>} />
        <Route path="/admin/invoices" element={<RequireRole role="admin"><AdminInvoices /></RequireRole>} />
        <Route path="/admin/invoices/:id" element={<RequireRole role="admin"><InvoiceView scope="admin" /></RequireRole>} />
        <Route path="/admin/memberships" element={<RequireRole role="admin"><AdminMemberships /></RequireRole>} />

        <Route path="/teacher" element={<RequireRole role="teacher"><MyCourses /></RequireRole>} />
        <Route path="/teacher/courses/:id" element={<RequireRole role="teacher"><CourseGrades /></RequireRole>} />
        <Route path="/teacher/tutoring" element={<RequireRole role="teacher"><TutoringRequests /></RequireRole>} />

        <Route path="/student" element={<RequireRole role="student"><StudentReport /></RequireRole>} />
        <Route path="/student/payments" element={<RequireRole role="student"><StudentPayments /></RequireRole>} />
        <Route path="/student/documents/:id" element={<RequireRole role="student"><DocumentView /></RequireRole>} />
        <Route path="/student/invoices/:id" element={<RequireRole role="student"><InvoiceView scope="student" /></RequireRole>} />
        <Route path="/student/membership" element={<RequireRole role="student"><Membership /></RequireRole>} />
        <Route path="/student/time" element={<RequireRole role="student"><Time /></RequireRole>} />
        <Route path="/student/study-rooms" element={<RequireRole role="student"><StudyRooms /></RequireRole>} />
        <Route path="/student/library" element={<RequireRole role="student"><Library /></RequireRole>} />
        <Route path="/student/tutoring" element={<RequireRole role="student"><Tutoring /></RequireRole>} />
        <Route path="/student/analytics" element={<RequireRole role="student"><Analytics /></RequireRole>} />
      </Route>

      <Route path="*" element={<Navigate to={user ? HOME[user.role] : '/login'} replace />} />
    </Routes>
  );
}
