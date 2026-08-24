import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import Layout from './Layout';
import { useAuth } from '../features/auth/AuthContext';

/*
 * ---------------------------------------------------------------------------
 * ROUTE-LEVEL CODE SPLITTING
 * ---------------------------------------------------------------------------
 * Every route below the public surface is loaded on demand.
 *
 * Before this, the whole application was one 537 kB chunk, which meant a first-
 * time visitor landing on the homepage downloaded and parsed the admin oversight
 * screen, the quote builder and every dashboard page before they could read the
 * hero. Those are the three largest modules in the codebase and none of them is
 * reachable without signing in.
 *
 * The split is drawn along the authentication boundary because that is where the
 * usage boundary already is:
 *
 *   - Eager: Layout, HomePage and the auth pages. These are on the critical path
 *     for a first visit, and lazy-loading them would only add a round trip
 *     before the first paint.
 *
 *   - Lazy: everything else. A visitor never pays for the dashboard; an owner
 *     never pays for the admin bundle.
 */
import HomePage from '../features/home/pages/HomePage';
import LoginPage from '../features/auth/pages/LoginPage';
import RegisterPage from '../features/auth/pages/RegisterPage';
import RegisterOwnerPage from '../features/auth/pages/RegisterOwnerPage';

/*
 * Password recovery is lazy: it is off the critical path for a first visit, and
 * most sessions never touch it. Unlike login and register, a moment's chunk fetch
 * here is invisible against the time the user has already spent looking for it.
 */
const ForgotPasswordPage = lazy(() => import('../features/auth/pages/ForgotPasswordPage'));
const ResetPasswordPage = lazy(() => import('../features/auth/pages/ResetPasswordPage'));

// --- Public, but not first-paint critical ---
const BrowsePage = lazy(() => import('../features/listings/pages/BrowsePage'));
const SuppliersPage = lazy(() => import('../features/suppliers/pages/SuppliersPage'));
const ListingDetailPage = lazy(() => import('../features/listings/pages/ListingDetailPage'));
const BusinessProfilePage = lazy(() => import('../features/business/pages/BusinessProfilePage'));

// --- Authenticated: listings and requirements ---
const CreateListingWizard = lazy(() => import('../features/listings/pages/CreateListingWizard'));
const MyListingsPage = lazy(() => import('../features/listings/pages/MyListingsPage'));
const MyRequirementsPage = lazy(() => import('../features/requirements/pages/MyRequirementsPage'));
const CreateRequirementWizard = lazy(() => import('../features/requirements/pages/CreateRequirementWizard'));
const RequirementDetailPage = lazy(() => import('../features/requirements/pages/RequirementDetailPage'));

// --- Dashboard shell and role homes ---
const DashboardLayout = lazy(() => import('../features/dashboard/pages/DashboardLayout'));
const OwnerDashboardPage = lazy(() => import('../features/dashboard/pages/OwnerDashboardPage'));
const CustomerDashboardPage = lazy(() => import('../features/dashboard/pages/CustomerDashboardPage'));

// --- Business management ---
const BusinessSettingsPage = lazy(() => import('../features/business/pages/BusinessSettingsPage'));
const ProductsPage = lazy(() => import('../features/business/pages/ProductsPage'));
const ServicesPage = lazy(() => import('../features/business/pages/ServicesPage'));
const CapabilitiesPage = lazy(() => import('../features/business/pages/CapabilitiesPage'));
const FavoritesPage = lazy(() => import('../features/business/pages/FavoritesPage'));
const VerificationPage = lazy(() => import('../features/business/pages/VerificationPage'));
const LeadsInboxPage = lazy(() => import('../features/leads/pages/LeadsInboxPage'));
const MatchedRequirementsPage = lazy(() => import('../features/matching/pages/MatchedRequirementsPage'));

// --- Transaction pipeline ---
const EnquiriesPage = lazy(() => import('../features/enquiries/pages/EnquiriesPage'));
const EnquiryDetailPage = lazy(() => import('../features/enquiries/pages/EnquiryDetailPage'));
const QuotesPage = lazy(() => import('../features/quotes/pages/QuotesPage'));
const QuoteBuilderPage = lazy(() => import('../features/quotes/pages/QuoteBuilderPage'));
const QuoteDetailPage = lazy(() => import('../features/quotes/pages/QuoteDetailPage'));
const QuoteComparisonPage = lazy(() => import('../features/quotes/pages/QuoteComparisonPage'));
const NegotiationsPage = lazy(() => import('../features/negotiations/pages/NegotiationsPage'));
const NegotiationDetailPage = lazy(() => import('../features/negotiations/pages/NegotiationDetailPage'));
const OrdersPage = lazy(() => import('../features/orders/pages/OrdersPage'));
const OrderDetailPage = lazy(() => import('../features/orders/pages/OrderDetailPage'));
const PaymentsPage = lazy(() => import('../features/payments/pages/PaymentsPage'));
const ReviewsPage = lazy(() => import('../features/reviews/pages/ReviewsPage'));
const DisputesPage = lazy(() => import('../features/disputes/pages/DisputesPage'));

// --- Admin. The largest group, and the one the fewest users ever load. ---
const AdminModerationPage = lazy(() => import('../features/admin/pages/AdminModerationPage'));
const AdminDashboardPage = lazy(() => import('../features/admin/pages/AdminDashboardPage'));
const AdminOversightPage = lazy(() => import('../features/admin/pages/AdminOversightPage'));
const AdminAiUsagePage = lazy(() => import('../features/admin/pages/AdminAiUsagePage'));

/**
 * Fallback shown while a route chunk is fetched.
 *
 * Deliberately a skeleton rather than a spinner or a blank area: it reserves the
 * page's approximate height, so arriving content does not shift the layout, and
 * it reads as "loading" without the impatience a spinner provokes on a fast
 * connection where it appears for 80ms.
 *
 * `role="status"` with `aria-live="polite"` announces the wait once to a screen
 * reader instead of leaving it silent.
 */
function RouteFallback() {
  return (
    <div className="mp-container py-5" role="status" aria-live="polite">
      <span className="mp-sr-only">Loading page…</span>
      <div className="mp-skeleton mp-skeleton--title mb-4" />
      <div className="mp-skeleton mp-skeleton--text mb-2" />
      <div className="mp-skeleton mp-skeleton--text mb-2" style={{ width: '78%' }} />
      <div className="mp-skeleton mp-skeleton--text" style={{ width: '54%' }} />
    </div>
  );
}

/**
 * Route guard. `role` restricts the route to that role only (frontend
 * convenience — the backend is the real source of truth and will 403
 * regardless of what this guard lets through). Omit `role` for any-role
 * routes that just require being logged in.
 */
function ProtectedRoute({
  children,
  role,
}: {
  children: React.ReactNode;
  role?: 'customer' | 'owner' | 'admin';
}) {
  const { isAuthenticated, isLoading, role: userRole } = useAuth();

  if (isLoading) {
    return <div className="mp-container py-5 text-center text-muted">Loading…</div>;
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (role && userRole !== role) {
    // Signed in, just not the right role for this route — send them
    // somewhere valid for their own role instead of a dead end.
    const fallback = userRole === 'admin' ? '/admin' : userRole === 'owner' ? '/dashboard' : '/dashboard/favorites';
    return <Navigate to={fallback} replace />;
  }

  return <>{children}</>;
}

/**
 * The `/dashboard` index route renders a different "home" per role instead
 * of redirecting — an Owner sees their listings/leads overview, a Customer
 * sees their saved favorites (their version of a dashboard home), an Admin
 * is sent to the platform overview.
 */
function DashboardIndexPage() {
  const { isOwner, isAdmin } = useAuth();

  if (isAdmin) {
    return <Navigate to="/admin" replace />;
  }

  // Phase 6: customers get a real, action-oriented dashboard rather than their
  // favourites list standing in for one.
  return isOwner ? <OwnerDashboardPage /> : <CustomerDashboardPage />;
}

export default function App() {
  return (
    /*
     * One Suspense boundary around all routes rather than one per route.
     *
     * Per-route boundaries would let each page define its own fallback, which
     * sounds better but in practice produces a different loading treatment on
     * every navigation. A single boundary keeps the transition consistent, and the
     * boundary sits inside Layout so the navbar and footer stay put while the
     * middle of the page swaps — navigation never flashes a bare white screen.
     */
    <Routes>
      <Route
        element={
          <Suspense fallback={<RouteFallback />}>
            <Layout />
          </Suspense>
        }
      >
        <Route index element={<HomePage />} />
        {/* Supplier discovery is the primary public surface; the listings
            catalogue at /browse is the secondary one. */}
        <Route path="suppliers" element={<SuppliersPage />} />
        <Route path="browse" element={<BrowsePage />} />
        <Route path="listings/:id" element={<ListingDetailPage />} />
        <Route path="business/:slug" element={<BusinessProfilePage />} />
        <Route path="login" element={<LoginPage />} />
        <Route path="register" element={<RegisterPage />} />
        <Route path="register/owner" element={<RegisterOwnerPage />} />
        {/* Both are public by necessity: the user cannot sign in, which is why
            they are here. `reset-password` reads its token from the query string. */}
        <Route path="forgot-password" element={<ForgotPasswordPage />} />
        <Route path="reset-password" element={<ResetPasswordPage />} />

        <Route
          path="listings/new"
          element={
            <ProtectedRoute role="owner">
              <CreateListingWizard />
            </ProtectedRoute>
          }
        />

        <Route
          path="requirements/new"
          element={
            <ProtectedRoute role="customer">
              <CreateRequirementWizard />
            </ProtectedRoute>
          }
        />
        <Route
          path="requirements/:id"
          element={
            <ProtectedRoute>
              <RequirementDetailPage />
            </ProtectedRoute>
          }
        />

        <Route
          path="dashboard"
          element={
            <ProtectedRoute>
              <DashboardLayout />
            </ProtectedRoute>
          }
        >
          <Route index element={<DashboardIndexPage />} />
          <Route
            path="listings"
            element={
              <ProtectedRoute role="owner">
                <MyListingsPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="listings/:id/edit"
            element={
              <ProtectedRoute role="owner">
                <CreateListingWizard />
              </ProtectedRoute>
            }
          />
          <Route
            path="business"
            element={
              <ProtectedRoute role="owner">
                <BusinessSettingsPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="products"
            element={
              <ProtectedRoute role="owner">
                <ProductsPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="services"
            element={
              <ProtectedRoute role="owner">
                <ServicesPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="capabilities"
            element={
              <ProtectedRoute role="owner">
                <CapabilitiesPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="leads"
            element={
              <ProtectedRoute role="owner">
                <LeadsInboxPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="matched-requirements"
            element={
              <ProtectedRoute role="owner">
                <MatchedRequirementsPage />
              </ProtectedRoute>
            }
          />

          {/* Phase 5. Enquiries and quotes are shared by both parties — the API
              scopes each list and authorises every action, so these routes only
              require being signed in, not a particular role. The quote builder is
              the one exception: only a business can author a quote. */}
          <Route path="enquiries" element={<ProtectedRoute><EnquiriesPage /></ProtectedRoute>} />
          <Route path="enquiries/:id" element={<ProtectedRoute><EnquiryDetailPage /></ProtectedRoute>} />
          <Route path="quotes" element={<ProtectedRoute><QuotesPage /></ProtectedRoute>} />
          <Route
            path="quotes/new"
            element={
              <ProtectedRoute role="owner">
                <QuoteBuilderPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="quotes/:id/edit"
            element={
              <ProtectedRoute role="owner">
                <QuoteBuilderPage />
              </ProtectedRoute>
            }
          />
          <Route path="quotes/:id" element={<ProtectedRoute><QuoteDetailPage /></ProtectedRoute>} />

          {/* Phase 6. Negotiations are shared by both parties; the API decides
              whose turn it is, so no role guard is needed here. */}
          <Route path="negotiations" element={<ProtectedRoute><NegotiationsPage /></ProtectedRoute>} />
          <Route path="negotiations/:id" element={<ProtectedRoute><NegotiationDetailPage /></ProtectedRoute>} />

          {/* Phase 7. Orders belong to both parties; the API decides which
              transitions each may make. */}
          <Route path="orders" element={<ProtectedRoute><OrdersPage /></ProtectedRoute>} />
          <Route path="orders/:id" element={<ProtectedRoute><OrderDetailPage /></ProtectedRoute>} />

          {/* Phases 8–10. All three are scoped and authorised per caller by the
              API, so they need a session but not a particular role. */}
          <Route path="payments" element={<ProtectedRoute><PaymentsPage /></ProtectedRoute>} />
          <Route path="reviews" element={<ProtectedRoute><ReviewsPage /></ProtectedRoute>} />
          <Route path="disputes" element={<ProtectedRoute><DisputesPage /></ProtectedRoute>} />
          <Route
            path="verification"
            element={
              <ProtectedRoute role="owner">
                <VerificationPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="requirements/:id/compare"
            element={
              <ProtectedRoute role="customer">
                <QuoteComparisonPage />
              </ProtectedRoute>
            }
          />
          <Route path="favorites" element={<FavoritesPage />} />
          <Route
            path="requirements"
            element={
              <ProtectedRoute role="customer">
                <MyRequirementsPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="requirements/:id/edit"
            element={
              <ProtectedRoute role="customer">
                <CreateRequirementWizard />
              </ProtectedRoute>
            }
          />
        </Route>

        <Route
          path="admin"
          element={
            <ProtectedRoute role="admin">
              <AdminDashboardPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="admin/listings"
          element={
            <ProtectedRoute role="admin">
              <AdminModerationPage />
            </ProtectedRoute>
          }
        />
        {/* Phase 10: verification, reviews, disputes and the audit trail, as four
            tabs on one screen rather than four near-identical pages. */}
        <Route
          path="admin/oversight"
          element={
            <ProtectedRoute role="admin">
              <AdminOversightPage />
            </ProtectedRoute>
          }
        />
        {/* Phase 11–14: AI usage and spend. Reads the request ledger, which holds no
            conversation content. */}
        <Route
          path="admin/ai-usage"
          element={
            <ProtectedRoute role="admin">
              <AdminAiUsagePage />
            </ProtectedRoute>
          }
        />
      </Route>
    </Routes>
  );
}
