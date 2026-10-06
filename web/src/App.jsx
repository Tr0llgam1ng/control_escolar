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

        <Route path="/admin" element={<RequireRole role="admin"><AdminDashboard /></RequireRole>} />
        <Route path="/admin/periods" element={<RequireRole role="admin"><PeriodsPage /></RequireRole>} />
        <Route path="/admin/subjects" element={<RequireRole role="admin"><SubjectsPage /></RequireRole>} />
        <Route path="/admin/teachers" element={<RequireRole role="admin"><TeachersPage /></RequireRole>} />
        <Route path="/admin/students" element={<RequireRole role="admin"><StudentsPage /></RequireRole>} />
        <Route path="/admin/courses" element={<RequireRole role="admin"><CoursesPage /></RequireRole>} />
        <Route path="/admin/courses/:id" element={<RequireRole role="admin"><CourseDetail /></RequireRole>} />

        <Route path="/teacher" element={<RequireRole role="teacher"><MyCourses /></RequireRole>} />
        <Route path="/teacher/courses/:id" element={<RequireRole role="teacher"><CourseGrades /></RequireRole>} />

        <Route path="/student" element={<RequireRole role="student"><StudentReport /></RequireRole>} />
      </Route>

      <Route path="*" element={<Navigate to={user ? HOME[user.role] : '/login'} replace />} />
    </Routes>
  );
}
